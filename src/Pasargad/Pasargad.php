<?php

namespace Vandar\Gateway\Pasargad;

use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Request;
use Log;
use Vandar\Gateway\Enum;
use Vandar\Gateway\Parsian\ParsianErrorException;
use Vandar\Gateway\PortAbstract;
use Vandar\Gateway\PortInterface;

class Pasargad extends PortAbstract implements PortInterface
{
  /**
   * Url of parsian gateway web service
   *
   * @var string
   */

  protected $checkTransactionUrl = 'https://pep.shaparak.ir/CheckTransactionResult.aspx';
  protected $verifyUrl = 'https://pep.shaparak.ir/Api/v1/Payment/VerifyPayment';
  // protected $verifyUrl = 'https://pep.shaparak.ir/VerifyPayment.aspx';
  protected $refundUrl = 'https://pep.shaparak.ir/Api/v1/Payment/RefundPayment';

  /**
   * Address of gate for redirect
   *
   * @var string
   */
  protected $gateUrl = 'https://pep.shaparak.ir/gateway.aspx';

  /**
   * {@inheritdoc}
   */
  public function set($amount)
  {
    $this->amount = intval($amount);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function ready()
  {
    $this->sendPayRequest();

    return $this;
  }

  /**
   * Send pay request to parsian gateway
   *
   * @param null $payment_id
   * @param null $callback_url
   * @return bool
   */
  protected function sendPayRequest($payment_id = null, $callback_url = null)
  {
    $this->newTransaction();
  }

  // /**
  //  * {@inheritdoc}
  //  */
  // public function ready($payment_id, $callback_url)
  // {
  //   $this->sendPayRequest($payment_id, $callback_url);

  //   return $this;
  // }

  /**
   * {@inheritdoc}
   */
  public function redirect()
  {

    $processor = new RSAProcessor($this->config->get('gateway.pasargad.certificate-path'), RSAKeyType::XMLFile);

    $url = $this->gateUrl;
    $redirectUrl = $this->getCallback();
    $invoiceNumber = $this->transactionId();
    $mobile = $this->getCellNumber();
    $amount = $this->amount;
    $terminalCode = $this->config->get('gateway.pasargad.terminalId');
    $merchantCode = $this->config->get('gateway.pasargad.merchantId');
    $timeStamp = date("Y/m/d H:i:s");
    $invoiceDate = date("Y/m/d H:i:s");
    $action = 1003;
    $data = "#" . $merchantCode . "#" . $terminalCode . "#" . $invoiceNumber . "#" . $invoiceDate . "#" . $amount . "#" . $redirectUrl . "#" . $action . "#" . $timeStamp . "#";
    $data = sha1($data, true);
    $data = $processor->sign($data); // امضاي ديجيتال
    $sign = base64_encode($data); // base64_encode

    return view('gateway::pasargad-redirector')->with(compact('url', 'redirectUrl', 'invoiceNumber', 'invoiceDate', 'amount', 'terminalCode', 'merchantCode', 'timeStamp', 'action', 'sign', 'mobile'));
  }

  /**
   * {@inheritdoc}
   */
  public function verify($transaction)
  {
    parent::verify($transaction);

    $this->verifyPayment();

    return $this;
  }

  /**
   * Sets callback url
   * @param $url
   * @return $this|string
   */
  function setCallback($url)
  {
    $this->callbackUrl = $url;
    return $this;
  }

  /**
   * Gets callback url
   * @return string
   */
  function getCallback()
  {
    if (!$this->callbackUrl)
      $this->callbackUrl = $this->config->get('gateway.pasargad.callback-url');

    return $this->callbackUrl;
  }

  // /**
  //  * Send pay request to parsian gateway
  //  *
  //  * @return bool
  //  *
  //  * @throws ParsianErrorException
  //  */
  // protected function sendPayRequest($payment_id, $callback_url)
  // {
  //   $this->newTransaction($payment_id, $callback_url);
  // }

  /**
   * Verify payment
   *
   * @throws ParsianErrorException
   */
  protected function verifyPayment()
  {
    $fields = array(
      'invoiceUID' => Request::input('tref'),
    );

    $result = Parser::post2https($fields, $this->checkTransactionUrl);
    $array = Parser::makeXMLTree($result);
    $array = $array['resultObj'];

    $verifyResult = $this->callVerifyPayment($array);

    $array['result'] = $verifyResult['IsSuccess'] ?? false;


    if ($array['result'] != "True") {
      $this->newLog(-1, Enum::TRANSACTION_FAILED_TEXT);
      $this->transactionFailed();
      throw new PasargadErrorException(Enum::TRANSACTION_FAILED_TEXT, -1);
    } else {
      $this->cardNumber = str_replace('-', '', $verifyResult['MaskedCardNumber']);
    }

    $this->refId = $array['transactionReferenceID'];
    $this->transactionSetRefId();

    $this->trackingCode = $array['traceNumber'];
    $this->transactionSucceed();
    $this->newLog($array['result'], Enum::TRANSACTION_SUCCEED_TEXT);
  }

  /**
   * @param $data
   * @return array
   */
  protected function callVerifyPayment($data)
  {
    $processor = new RSAProcessor($this->config->get('gateway.pasargad.certificate-path'), RSAKeyType::XMLFile);
    $merchantCode = $this->config->get('gateway.pasargad.merchantId');
    $terminalCode = $this->config->get('gateway.pasargad.terminalId');
    $invoiceNumber = $data['invoiceNumber'];
    $invoiceDate = $data['invoiceDate'];
    $timeStamp = date("Y/m/d H:i:s");
    $amount = $data['amount'];

    $signData = [
      'merchantCode' => $merchantCode,
      'terminalCode' => $terminalCode,
      'invoiceNumber' => $invoiceNumber,
      'invoiceDate' => $invoiceDate,
      'amount' => $amount,
      'timeStamp' => $timeStamp
    ];
    $signDataSha1 = sha1(json_encode($signData), true);
    $tempSign = $processor->sign($signDataSha1);
    $sign = base64_encode($tempSign);

    $verify = new Client();
    $result = $verify->request(
      'POST',
      $this->verifyUrl,
      [
        'headers' => [
          'Content-Type' => 'Application/json',
          'Sign' => $sign
        ],
        'json' => [
          'merchantCode' => $merchantCode,
          'terminalCode' => $terminalCode,
          'invoiceNumber' => $invoiceNumber,
          'invoiceDate' => $invoiceDate,
          'amount' => $amount,
          'timeStamp' => $timeStamp
        ]
      ]
    );

    return json_decode($result->getBody(), true);
  }

  public function refund($transaction)
  {
    parent::refund($transaction);

    return $this->refundPayment($transaction);
  }

  public function refundPayment($transaction)
  {
    $fields = array(
      'invoiceUID' => $transaction->ref_id,
    );

    $result = Parser::post2https($fields, $this->checkTransactionUrl);
    $array = Parser::makeXMLTree($result);
    $array = $array['resultObj'];

    $processor = new RSAProcessor($this->config->get('gateway.pasargad.certificate-path'), RSAKeyType::XMLFile);
    $merchantCode = $this->config->get('gateway.pasargad.merchantId');
    $terminalCode = $this->config->get('gateway.pasargad.terminalId');
    $invoiceNumber = $array['invoiceNumber'];
    $invoiceDate = $array['invoiceDate'];
    $timeStamp = date("Y/m/d H:i:s");

    $signData = [
      'merchantCode' => $merchantCode,
      'terminalCode' => $terminalCode,
      'invoiceNumber' => $invoiceNumber,
      'invoiceDate' => $invoiceDate,
      'timeStamp' => $timeStamp
    ];

    Log::info($signData);

    $signDataSha1 = sha1(json_encode($signData), true);
    $tempSign = $processor->sign($signDataSha1);
    $sign = base64_encode($tempSign);

    $refund = new Client();
    $result = $refund->request(
      'POST',
      $this->refundUrl,
      [
        'headers' => [
          'Content-Type' => 'Application/json',
          'Sign' => $sign
        ],
        'json' => [
          'merchantCode' => $merchantCode,
          'terminalCode' => $terminalCode,
          'invoiceNumber' => $invoiceNumber,
          'invoiceDate' => $invoiceDate,
          'timeStamp' => $timeStamp
        ]
      ]
    );

    Log::info($result->getBody());

    return json_decode($result->getBody(), true);
  }

  /**
   * @param string $xmlString
   * @return array
   */
  private function convertXMLtoArray($xmlString)
  {
    $xml = simplexml_load_string($xmlString, "SimpleXMLElement", LIBXML_NOCDATA);
    $json = json_encode($xml);

    return json_decode($json, True);
  }

  function setCellNumber($cellNumner)
  {
    $this->cellNumber = $cellNumner;
    return $this;
  }

  function getCellNumber()
  {
    return $this->cellNumber;
  }

  function setMerchant($merchant)
  {
    $this->merchant = $merchant;
    return $this;
  }

  function getMerchant()
  {
    if (!$this->merchant)
      $this->merchant = $this->config->get('gateway.saman.merchant');

    return $this->merchant;
  }

  function setMerchantPassword($password)
  {
    $this->merchant_password = $password;
    return $this;
  }

  function getMerchantPassword()
  {
    if (!$this->merchant_password)
      $this->merchant_password = $this->config->get('gateway.saman.password');

    return $this->merchant_password;
  }

    public function setNationalCode($national_code)
    {
        // TODO: Implement setNationalCode() method.
    }

    public function getNationalCode()
    {
        // TODO: Implement getNationalCode() method.
    }
}
