<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 * @author Semenov Alexander <semenov@skeeks.com>
 */

namespace skeeks\cms\shop\dolyame\controllers;

use skeeks\cms\shop\models\ShopBill;
use skeeks\cms\shop\models\ShopPayment;
use skeeks\cms\shop\sberbank\DolyamePaysystemHandler;
use yii\base\Exception;
use yii\helpers\ArrayHelper;
use yii\helpers\Json;
use yii\helpers\Url;
use yii\web\Controller;
use YooKassa\Client;

/**
 * @author Semenov Alexander <semenov@skeeks.com>
 */
class DolyameController extends Controller
{

    /**
     * @var bool
     */
    public $enableCsrfValidation = false;

    public function actionNotification() {

        $dataJson = file_get_contents("php://input");
        \Yii::info("Dolyame notification" . file_get_contents("php://input"), static::class);
        $data = Json::decode($dataJson);

        /**
         * @var $bill ShopBill
         */
        if (!$code = \Yii::$app->request->get('code')) {
            throw new Exception('Bill not found');
        }

        if (!$bill = ShopBill::find()->cmsSite()->where(['code' => $code])->one()) {
            throw new Exception('Bill not found');
        }

        $status = ArrayHelper::getValue($data, "status");
        $shopOrder = $bill->shopOrder;

        /**
         * @var $dolyameHandler \skeeks\cms\shop\dolyame\DolyamePaysystemHandler
         */
        $dolyameHandler = $shopOrder->shopPaySystem->handler;
        $uniqueId = \skeeks\cms\shop\dolyame\DolyamePaysystemHandler::getUniqueOrderId($bill);
        
        if ($status == 'rejected') {
            //Заявка отклонена, клиенту стоит выбрать другой способ оплаты
            $bill->closed_at = time();
            $bill->external_data = [];
            $bill->external_id = "";
            $bill->save();
            
            $response = $dolyameHandler->sendRequest("{$uniqueId}/cancel");
            
        } elseif ($status == 'canceled') {
            //Заявка была по какой-либо причине отменена
            $bill->external_data = [];
            $bill->closed_at = time();
            $bill->external_id = "";
            $bill->save();
            
            $response = $dolyameHandler->sendRequest("{$uniqueId}/cancel");
            
        } elseif ($status == 'wait_for_commit') {
            //Заявка была одобрена, средства захолдированы, Тинькофф ожидает commit от партнера (готов принять commit от партнера)
            
            $items = \skeeks\cms\shop\dolyame\DolyamePaysystemHandler::getDataItemsForOrder($shopOrder);
            
            $response = $dolyameHandler->sendRequest("{$uniqueId}/commit", [
                'items' => $items,
                'amount' => (float) $shopOrder->money->amount,
            ]);

            if ($response->isOk) {

            } else {
                $bill->external_data = [];
                $bill->closed_at = time();
                $bill->external_id = "";
                $bill->save();
            }

        } elseif ($status == 'committed') {
            //ИМ прислал commit, первый платеж будет списан (до этого он был захолдирован, вскоре будет списан)
        } elseif ($status == 'completed') {
            //Первый платеж по заявке был списан (подтверждение того, что платеж был списан)
            
            
            $transaction = \Yii::$app->db->beginTransaction();

            try {

                $payment = new ShopPayment();
                $payment->cms_user_id = $bill->cms_user_id;
                $payment->shop_pay_system_id = $bill->shop_pay_system_id;
                $payment->shop_order_id = $bill->shop_order_id;
                $payment->amount = $bill->amount;
                $payment->currency_code = $bill->currency_code;
                $payment->comment = "Оплата по счету №{$bill->id} от ".\Yii::$app->formatter->asDate($bill->created_at);
                $payment->external_data = $data;

                if (!$payment->save()) {
                    throw new Exception("Не сохранился платеж: ".print_r($payment->errors, true));
                }

                $bill->isNotifyUpdate = false;
                $bill->paid_at = time();
                $bill->shop_payment_id = $payment->id;

                if (!$bill->save()) {
                    throw new Exception("Не обновился счет: ".print_r($payment->errors, true));
                }

                $bill->shopOrder->paid_at = time();
                $bill->shopOrder->save();

                $transaction->commit();

                return true;

            } catch (\Exception $e) {
                $transaction->rollBack();
                \Yii::error($e->getMessage(), self::class);
                return false;
            }
            
        }

        return true;
    }

    public function actionSuccess()
    {
        $dataJson = file_get_contents("php://input");
        \Yii::info("Dolyame success" . file_get_contents("php://input"), static::class);
        $data = Json::decode($dataJson);

        /**
         * @var $bill ShopBill
         */
        if (!$code = \Yii::$app->request->get('code')) {
            throw new Exception('Bill not found');
        }

        if (!$bill = ShopBill::find()->where(['code' => $code])->one()) {
            throw new Exception('Bill not found');
        }
        
        return $this->redirect($bill->shopOrder->getPublicUrl(\Yii::$app->request->get()));


        

        $data = [
            'userName'    => $bill->shopPaySystem->handler->username,
            'password'    => $bill->shopPaySystem->handler->password,
            'orderNumber' => urlencode($bill->id),
        ];

        $response = $bill->shopPaySystem->handler->gateway('getOrderStatusExtended.do', $data);

        /**
         * Оплата произведена
         */
        if (ArrayHelper::getValue($response, 'orderStatus') == DolyamePaysystemHandler::ORDER_STATUS_2 && ArrayHelper::getValue($response, 'orderNumber') == $bill->id) {

            $transaction = \Yii::$app->db->beginTransaction();

            try {

                $payment = new ShopPayment();
                $payment->cms_user_id = $bill->cms_user_id;
                $payment->shop_pay_system_id = $bill->shop_pay_system_id;
                $payment->shop_order_id = $bill->shop_order_id;
                $payment->amount = $bill->amount;
                $payment->currency_code = $bill->currency_code;
                $payment->comment = "Оплата по счету №{$bill->id} от ".\Yii::$app->formatter->asDate($bill->created_at);
                $payment->external_data = $response;

                if (!$payment->save()) {
                    throw new Exception("Не сохранился платеж: ".print_r($payment->errors, true));
                }

                $bill->isNotifyUpdate = false;
                $bill->paid_at = time();
                $bill->shop_payment_id = $payment->id;

                if (!$bill->save()) {
                    throw new Exception("Не обновился счет: ".print_r($payment->errors, true));
                }

                $bill->shopOrder->paid_at = time();
                $bill->shopOrder->save();

                $transaction->commit();

                return $this->redirect($bill->shopOrder->url);

            } catch (\Exception $e) {
                $transaction->rollBack();
                \Yii::error($e->getMessage(), self::class);
                throw $e;
            }

        }

        return $this->redirect(Url::toRoute(['/dolyame/dolyame/fail', 'code' => $code, 'response' => Json::encode($response)], true));
    }



    public function actionFail()
    {
        \Yii::info("Dolyame fail" . file_get_contents("php://input"), static::class);

        /**
         * @var $bill ShopBill
         */
        if (!$code = \Yii::$app->request->get('code')) {
            throw new Exception('Bill not found');
        }

        if (!$bill = ShopBill::find()->where(['code' => $code])->one()) {
            throw new Exception('Bill not found');
        }

        return $this->redirect($bill->shopOrder->getPublicUrl(\Yii::$app->request->get()));
    }

}