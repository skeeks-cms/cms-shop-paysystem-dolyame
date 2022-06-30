<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 * @author Semenov Alexander <semenov@skeeks.com>
 */

namespace skeeks\cms\shop\dolyame;

use skeeks\cms\helpers\StringHelper;
use skeeks\cms\shop\models\ShopOrder;
use skeeks\cms\shop\models\ShopPayment;
use skeeks\cms\shop\paysystem\PaysystemHandler;
use skeeks\yii2\form\fields\BoolField;
use skeeks\yii2\form\fields\FieldSet;
use yii\base\Exception;
use yii\helpers\ArrayHelper;
use yii\helpers\Json;
use yii\helpers\Url;
use yii\httpclient\Client;

/**
 * @author Semenov Alexander <semenov@skeeks.com>
 */
class DolyamePaysystemHandler extends PaysystemHandler
{
    /**
     * @see https://developer.sberbank.ru/acquiring-api-rest-requests1pay
     */
    const ORDER_STATUS_2 = 2; //Проведена полная авторизация суммы заказа


    public $isLive = true; //https://auth.robokassa.ru/Merchant/Index.aspx

    public $gatewayUrl = 'https://securepayments.sberbank.ru/payment/rest/';
    public $gatewayTestUrl = 'https://3dsec.sberbank.ru/payment/rest/';
    public $thanksUrl = '/main/spasibo-za-zakaz';
    public $failUrl = '/main/problema-s-oplatoy';
    public $currency = 'RUB';
    public $username = '';
    public $password = '';
    
    /**
     * Можно задать название и описание компонента
     * @return array
     */
    static public function descriptorConfig()
    {
        return array_merge(parent::descriptorConfig(), [
            'name' => \Yii::t('skeeks/shop/app', 'Dolyame'),
        ]);
    }


    public function rules()
    {
        return ArrayHelper::merge(parent::rules(), [
            [['isLive'], 'boolean'],
            [['username'], 'string'],
            [['password'], 'string'],
        ]);
    }

    public function attributeLabels()
    {
        return ArrayHelper::merge(parent::attributeLabels(), [
            'username' => 'Идентификатор магазина из ЛК',
            'password' => 'Пароль',
            'isLive'   => 'Рабочий режим (не тестовый!)',
        ]);
    }

    public function attributeHints()
    {
        return ArrayHelper::merge(parent::attributeHints(), [
            'isLive' => 'Будет использован url: https://securepayments.sberbank.ru/payment/rest/ (тестовый: https://3dsec.sberbank.ru/payment/rest/)',
        ]);
    }
    
    /**
     * @return array
     */
    public function getConfigFormFields()
    {
        return [
            'main' => [
                'class'  => FieldSet::class,
                'name'   => 'Основные',
                'fields' => [
                    'username',
                    'password',

                    'isLive' => [
                        'class'     => BoolField::class,
                        'allowNull' => false,
                    ],
                ],
            ],

        ];
    }
    
    /**
     * @param $method
     * @param $data
     * @return mixed
     */
    public function gateway($method, $data)
    {
        $curl = curl_init(); // Инициализируем запрос
        curl_setopt_array($curl, [
            CURLOPT_URL            => ($this->isLive ? $this->gatewayUrl : $this->gatewayTestUrl).$method, // Полный адрес метода
            CURLOPT_RETURNTRANSFER => true, // Возвращать ответ
            CURLOPT_POST           => true, // Метод POST
            CURLOPT_POSTFIELDS     => http_build_query($data) // Данные в запросе
        ]);
        $response = curl_exec($curl); // Выполненяем запрос
        $response = json_decode($response, true); // Декодируем из JSON в массив
        curl_close($curl); // Закрываем соединение
        return $response; // Возвращаем ответ
    }
    
    
    /**
     * @param ShopPayment $shopPayment
     * @return \yii\console\Response|\yii\web\Response
     */
    public function actionPayOrder(ShopOrder $shopOrder)
    {
        $bill = $this->getShopBill($shopOrder);

        $sber = $bill->shopPaySystem->handler;
        $money = $bill->money->convertToCurrency("RUB");
        $returnUrl = $shopOrder->getUrl([], true);
        $successUrl = $shopOrder->getUrl(['success_paied' => true], true);
        $failUrl = $shopOrder->getUrl(['fail_paied' => true], true);

        /**
         * Для чеков нужно указывать информацию о товарах
         * https://yookassa.ru/developers/api?lang=php#create_payment
         */


        if (isset($bill->external_data['formUrl'])) {
            return \Yii::$app->response->redirect($bill->external_data['formUrl']);
        }

        $data = [
            'userName'    => $bill->shopPaySystem->handler->username,
            'password'    => $bill->shopPaySystem->handler->password,
            'description' => "Заказ в магазине №{$bill->shopOrder->id}",
            'orderNumber' => urlencode($bill->id),
            'amount'      => urlencode($bill->money->amount * 100), // передача данных в копейках/центах
            'returnUrl'   => Url::toRoute(['/sberbank/sberbank/success', 'code' => urlencode($bill->code)], true),
            'failUrl'     => Url::toRoute(['/sberbank/sberbank/fail', 'code' => urlencode($bill->code)], true),
        ];

        if ($bill->shopOrder && $bill->shopOrder->contact_email) {
            $data['jsonParams'] = '{"email":"'.$bill->shopOrder->contact_email.'"}';
        }

        $response = $this->gateway('register.do', $data);

        if (isset($response['errorCode'])) { // В случае ошибки вывести ее
            return \Yii::$app->response->redirect(Url::toRoute(['/sberbank/sberbank/fail', 'code' => urlencode($bill->code), 'response' => Json::encode($response)], true));
        } else { // В случае успеха перенаправить пользователя на плетжную форму
            $bill->external_data = $response;
            if (!$bill->save()) {

                //TODO: Add logs
                print_r($bill->errors);
                die;
            }

            return \Yii::$app->response->redirect($bill->external_data['formUrl']);
        }
    }
}