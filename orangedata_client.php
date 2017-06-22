<?php

/*
 * 
 */

namespace orangedata;

/**
 * Description of orangedata_client
 *
 * @author 
 */
class orangedata_client {

    private $order_request;
    private $url;
    private $inn;
    private $debug_file;

    public function __construct($inn, $url, $sign_pkey, $ca_cert, $client_cert, $client_cert_pass) {
        $this->inn = $inn;
        $this->url = $url;
        $this->private_key_pem = $sign_pkey;
        $this->ca_cert = $ca_cert;
        $this->client_cert = $client_cert;
        $this->client_cert_pass = $client_cert_pass;
        $this->debug_file = getcwd() . '/curl.log';
    }

    /**
     * 
     * @param string $id уникальный для ИНН идентификатор чека
     * @param integer $type 1=Приход,2=Возврат прихода,3=Расход,4=Возврат расхода
     * @param string $customerContact Телефон или e-mail покупателя
     * @param integer $TaxationSystem Система налогообложения:
     *       0 – Общая, ОСН
     *       1 – Упрощенная доход, УСН доход
     *       2 – Упрощенная доход минус расход, УСН доход - расход
     *       3 – Единый налог на вмененный доход, ЕНВД
     *       4 – Единый сельскохозяйственный налог, ЕСН
     *       5 – Патентная система налогообложения, Патент,
     * @param string $group идентификатор группы чеков
     * @return $this
     * @throws Exception
     */
    public function create_order($id, $type, $customerContact, $TaxationSystem, $group = null) {
        $this->order_request = new \stdClass();
        $this->order_request->Id = $id;
        $this->order_request->INN = $this->inn;

        //set group if exist
        if ($group != null) {
            $this->order_request->Group = $group;
        }

        $this->order_request->Content = new \stdClass();

        //set positions of order
        $this->order_request->Content->Positions = array();

        //set parameters of order closing
        $this->order_request->Content->CheckClose = new \stdClass();

        //set payments of order
        $this->order_request->Content->CheckClose->Payments = array();

        if (preg_match('/^[012345]$/', $TaxationSystem)) {
            $this->order_request->Content->CheckClose->TaxationSystem = $TaxationSystem;
        } else {
            throw new \Exception('Incorrect TaxationSystem');
        }
        //set type
        if (is_int($type)) {
            $this->order_request->Content->Type = $type;
        } else {
            throw new \Exception('Incorrect order Type');
        }

        //set customer contact
        if (preg_match('/^\+\d+$|^[a-zA-Z]+\@\S+$/', $customerContact)) {
            $this->order_request->Content->CustomerContact = $customerContact;
        } else {
            throw new \Exception('Incorrect customer Contact');
        }

        return $this;
    }

    /**
     * 
     * @param float $quantity Количество товара, Десятичное число с точностью до 3 символов после точки
     * @param int $price Десятичное число в копейках
     * @param int $tax Ставка НДС:
     * 1 – ставка НДС 18%
     * 2 – ставка НДС 10%
     * 3 – ставка НДС расч. 18/118
     * 4 – ставка НДС расч. 10/110
     * 5 – ставка НДС 0%
     * 6 – НДС не облагается
     * @param string $text Строка до 128 символов
     * @return $this
     * @throws Exception
     */
    public function add_position_to_order(float $quantity, int $price, int $tax, string $text) {
        if (is_float($quantity) and is_int($price) and preg_match('/^[123456]{1}$/', $tax) and strlen($text) < 129) {
            $position = new \stdClass();
            $position->Quantity = $quantity;
            $position->Price = $price / 100;
            $position->Tax = $tax;
            $position->Text = $text;
            $this->order_request->Content->Positions[] = $position;
        } Else {
            throw new \Exception('Invalid Position Quantity, Price, Tax or Text');
        }
        return $this;
    }

    /**
     * 
     * @param int $type  * Тип оплаты:
     * 1 – Наличными
     * 2 – Картой Мир
     * 3 – Картой Visa
     * 4 – Картой MasterCard
     * 5 – Расширенная оплата 1
     * 6 – Расширенная оплата 2
     * 7 – Расширенная оплата 3
     * 8 – Расширенная оплата 4
     * 9 – Расширенная оплата 5
     * 10 – Расширенная оплата 6
     * 11 – Расширенная оплата 7
     * 12 – Расширенная оплата 8
     * 13 – Расширенная оплата 9
     * 14 – Предвариательная оплата(Аванс)
     * 15 – Последующая оплата(Кредит)
     * 16 – Иная форма оплаты
     * @param int $amount Десятичное число в копейках 
     * @return $this
     * @throws Exception
     */
    public function add_payment_to_order(int $type, int $amount) {
        if (preg_match('/^[123456789]{1}$|^1[0123456]{1}$/', $type) and is_int($amount)) {
            $payment = new \stdClass();
            $payment->Type = $type;
            $payment->Amount = $amount / 100;
            $this->order_request->Content->CheckClose->Payments[] = $payment;
        } Else {
            throw new \Exception('Invalid Payment Type or Amount');
        }
        return $this;
    }

    public function send_order() {
        $jsonstring = json_encode($this->order_request, JSON_PRESERVE_ZERO_FRACTION);
        $sign = $this->sign_order_request($jsonstring);

        $curl = curl_init($this->url);
        $headers = array(
            "X-Signature: " . $sign,
            "Connection: Keep-Alive",
            "Content-Length: " . strlen($jsonstring),
            "Content-Type: application/json; charset=utf-8",
            "Expect: 100-continue"
        );
        curl_setopt($curl, CURLOPT_CAINFO, $this->ca_cert);
        curl_setopt($curl, CURLOPT_SSLCERT, $this->client_cert);
        curl_setopt($curl, CURLOPT_SSLCERTPASSWD, $this->client_cert_pass);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $jsonstring);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($curl, CURLOPT_TIMEOUT, 30);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);

        $answer = curl_exec($curl);
        if (!$answer) {
            throw new \Exception(curl_error($curl));
        }
        return $answer;
    }

    public function get_order_status($id) {
        $url = $this->url . $this->inn . '/status/' . $id;
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_CAINFO, $this->ca_cert);
        curl_setopt($curl, CURLOPT_SSLCERT, $this->client_cert);
        curl_setopt($curl, CURLOPT_SSLCERTPASSWD, $this->client_cert_pass);
        curl_setopt($curl, CURLOPT_POST, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($curl, CURLOPT_TIMEOUT, 30);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($curl, CURLOPT_VERBOSE, 1);
        curl_setopt($curl, CURLOPT_STDERR, fopen($this->debug_file, 'a'));
        $answer = curl_exec($curl);
        if (!$answer) {
            throw new \Exception(curl_error($curl));
        }
        return $answer;
    }

    private function sign_order_request($jsonstring) {
        $binary_signature = "";
        $r = openssl_sign($jsonstring, $binary_signature, $this->private_key_pem, OPENSSL_ALGO_SHA256);
        if ($r) {
            return base64_encode($binary_signature);
        } else {
            return false;
        }
    }

}
