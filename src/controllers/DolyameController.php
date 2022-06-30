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



    public function actionSuccess()
    {
        \Yii::info("Sberbank success: " . print_r(\Yii::$app->request->get(), true), static::class);

        /**
         * @var $bill ShopBill
         */
        if (!$code = \Yii::$app->request->get('code')) {
            throw new Exception('Bill not found');
        }

        if (!$bill = ShopBill::find()->where(['code' => $code])->one()) {
            throw new Exception('Bill not found');
        }


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

        return $this->redirect(Url::toRoute(['/sberbank/sberbank/fail', 'code' => $code, 'response' => Json::encode($response)], true));
    }



    public function actionFail()
    {
        \Yii::warning("Sberbank fail: ".print_r(\Yii::$app->request->get(), true), self::class);

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