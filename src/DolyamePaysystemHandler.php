<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 * @author Semenov Alexander <semenov@skeeks.com>
 */

namespace skeeks\cms\shop\dolyame;

use skeeks\cms\backend\widgets\SelectModelDialogStorageFileSrcWidget;
use skeeks\cms\helpers\StringHelper;
use skeeks\cms\shop\models\ShopBill;
use skeeks\cms\shop\models\ShopOrder;
use skeeks\cms\shop\models\ShopPayment;
use skeeks\cms\shop\paysystem\PaysystemHandler;
use skeeks\yii2\form\fields\FieldSet;
use skeeks\yii2\form\fields\WidgetField;
use yii\helpers\ArrayHelper;
use yii\helpers\Json;
use yii\helpers\Url;
use yii\httpclient\Client;

/**
 * @author Semenov Alexander <semenov@skeeks.com>
 */
class DolyamePaysystemHandler extends PaysystemHandler
{
    public $login = '';
    public $password = '';

    public $private_key = '';
    public $open_api_cert = '';

    public $base_api_url = 'https://partner.dolyame.ru/v1/orders/';

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
            [['login'], 'string'],
            [['password'], 'string'],
            [['private_key'], 'string'],
            [['open_api_cert'], 'string'],
        ]);
    }

    public function attributeLabels()
    {
        return ArrayHelper::merge(parent::attributeLabels(), [
            'login'         => 'Логин',
            'password'      => 'Пароль',
            'private_key'   => 'private.key',
            'open_api_cert' => 'open_api_cert.pem',
        ]);
    }

    public function attributeHints()
    {
        return ArrayHelper::merge(parent::attributeHints(), [
            'private_key' => '',
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
                    'login',
                    'password',
                    'private_key'   => [
                        'class'       => WidgetField::class,
                        'widgetClass' => SelectModelDialogStorageFileSrcWidget::class,
                    ],
                    'open_api_cert' => [
                        'class'       => WidgetField::class,
                        'widgetClass' => SelectModelDialogStorageFileSrcWidget::class,
                    ],
                ],
            ],

        ];
    }


    /**
     * @param $action
     * @param $data
     * @param $post
     * @return \yii\httpclient\Response
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\httpclient\Exception
     */
    public function sendRequest($action, $data = [], $post = true)
    {
        $url = $this->base_api_url . $action;
        $uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));

        $client = new Client([
            'transport' => 'yii\httpclient\CurlTransport' //только cURL поддерживает нужные нам параметры
        ]);

        \Yii::info("Dolyame request to {$url}: " . print_r($data, true), static::class);

        $response = $client->createRequest()
            ->setMethod($post ? "POST" : "GET")
            ->setUrl($url)
            ->setData($data)
            ->setFormat(Client::FORMAT_JSON)
            ->setHeaders([
                'X-Correlation-ID' => $uuid
            ])
            ->setOptions([
                CURLOPT_RETURNTRANSFER => true, // тайм-аут подключения
                CURLOPT_FOLLOWLOCATION => true, // тайм-аут получения данных
                CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2, // тайм-аут получения данных
                CURLOPT_SSLCERT => \Yii::getAlias("@webroot") . $this->open_api_cert, // тайм-аут получения данных
                CURLOPT_CAINFO => \Yii::getAlias("@webroot") . $this->open_api_cert, // тайм-аут получения данных
                CURLOPT_SSLKEY => \Yii::getAlias("@webroot") . $this->private_key, // тайм-аут получения данных
                CURLOPT_USERPWD => $this->login . ":" . $this->password, // тайм-аут получения данных
            ])
            ->send();

        return $response;
    }


    /**
     * @param ShopOrder $shopOrder
     * @return array
     */
    static public function getDataItemsForOrder(ShopOrder $shopOrder) {
        $items = [];
        foreach ($shopOrder->shopOrderItems as $shopOrderItem) {
            $itemData = [];

            /**
             * @see https://www.tinkoff.ru/kassa/develop/api/payments/init-request/#Items
             */
            $itemData['name'] = StringHelper::substr($shopOrderItem->name, 0, 128);
            $itemData['quantity'] = (float)$shopOrderItem->quantity;
            $itemData['price'] = $shopOrderItem->money->amount;

            $items[] = $itemData;
        }
        
        if ((float)$shopOrder->moneyDelivery->amount > 0) {
            $itemData = [];
            $itemData['name'] = StringHelper::substr($shopOrder->shopDelivery->name, 0, 128);
            $itemData['quantity'] = 1;
            $itemData['price'] = $shopOrder->moneyDelivery->amount;
            $items[] = $itemData;
        }

        
        return $items;
    }

  

    /**
     * @param ShopBill $shopBill
     * @return string
     */
    static public function getUniqueOrderId(ShopBill $shopBill)
    {
        return "sx_" . $shopBill->shop_order_id . "_" . $shopBill->id;
    }
    
    /**
     * @param ShopPayment $shopPayment
     * @return \yii\console\Response|\yii\web\Response
     */
    public function actionPayOrder(ShopOrder $shopOrder)
    {
        $bill = $this->getShopBill($shopOrder);


        $dolyame = $bill->shopPaySystem->handler;
        $money = $bill->money->convertToCurrency("RUB");

        /**
         * Для чеков нужно указывать информацию о товарах
         * https://yookassa.ru/developers/api?lang=php#create_payment
         */


        if (isset($bill->external_data['link'])) {
            return \Yii::$app->response->redirect($bill->external_data['link']);
        }

        $client_info = [];
        if ($shopOrder->contact_first_name) {
            $client_info['first_name'] = $shopOrder->contact_first_name;
        }
        if ($shopOrder->contact_last_name) {
            $client_info['last_name'] = $shopOrder->contact_last_name;
        }
        if ($shopOrder->contact_phone) {
            $client_info['phone'] = $shopOrder->contact_phone;
        }
        if ($shopOrder->contact_email) {
            $client_info['email'] = $shopOrder->contact_email;
        }

        $data = [
            'order' => [
                'id' => self::getUniqueOrderId($bill),
                'amount' => (float) $bill->money->amount,
                'items' => self::getDataItemsForOrder($shopOrder),
            ],
            'client_info' => $client_info,
            'notification_url'   => Url::to(['/dolyame/dolyame/notification', 'code' => urlencode($bill->code)], true),
            'success_url'   => Url::to(['/dolyame/dolyame/success', 'code' => urlencode($bill->code)], true),
            'fail_url'     => Url::to(['/dolyame/dolyame/fail', 'code' => urlencode($bill->code)], true),
        ];
        
        $response = $this->sendRequest('create', $data);

        if (!$response->isOk) { // В случае ошибки вывести ее

            \Yii::error("Dolyame error create order: " . $response->content, static::class);
            print_r($response->content);
            die;

        } else { // В случае успеха перенаправить пользователя на плетжную форму
            
            $bill->external_data = $response->data;
            $bill->external_id = self::getUniqueOrderId($bill);
            
            if (!$bill->save()) {

                //TODO: Add logs
                print_r($bill->errors);
                die;
            }

            return \Yii::$app->response->redirect($bill->external_data['link']);
        }
    }
}