<?php

/**
 * ЮKassa
 * Версия 2.1.1
 *
 * Лицензионный договор:
 * Любое использование Вами программы означает полное и безоговорочное принятие Вами условий лицензионного договора,
 * размещенного по адресу https://yoomoney.ru/doc.xml?id=527132 (далее – «Лицензионный договор»).
 * Если Вы не принимаете условия Лицензионного договора в полном объёме,
 * Вы не имеете права использовать программу в каких-либо целях.
 */
use YooKassa\Client;
use YooKassa\Common\Exceptions\ApiException;
use YooKassa\Common\Exceptions\BadApiRequestException;
use YooKassa\Common\Exceptions\ExtensionNotFoundException;
use YooKassa\Common\Exceptions\ForbiddenException;
use YooKassa\Common\Exceptions\InternalServerError;
use YooKassa\Common\Exceptions\NotFoundException;
use YooKassa\Common\Exceptions\ResponseProcessingException;
use YooKassa\Common\Exceptions\TooManyRequestsException;
use YooKassa\Common\Exceptions\UnauthorizedException;
use YooKassa\Model\ConfirmationType;
use YooKassa\Model\Notification\NotificationFactory;
use YooKassa\Model\Payment;
use YooKassa\Model\PaymentStatus;
use YooKassa\Model\Receipt;
use YooKassa\Model\Receipt\PaymentMode;
use YooKassa\Model\Receipt\PaymentSubject;
use YooKassa\Model\ReceiptCustomer;
use YooKassa\Model\ReceiptItem;
use YooKassa\Model\ReceiptType;
use YooKassa\Model\Settlement;
use YooKassa\Request\Payments\CreatePaymentRequest;
use YooKassa\Request\Payments\CreatePaymentResponse;
use YooKassa\Request\Payments\Payment\CreateCaptureRequest;
use YooKassa\Request\Receipts\CreatePostReceiptRequest;
use YooKassa\Request\Receipts\ReceiptResponseInterface;
use YooKassa\Request\Receipts\ReceiptResponseItemInterface;

require_once CMS_FOLDER.'yoomoney'.DIRECTORY_SEPARATOR.'autoload.php';

class Shop_Payment_System_HandlerXX extends Shop_Payment_System_Handler
{
    /**
     * Адрес для уведомлений: https://[ваш-сайт]/shop/cart/?action=notify
     *
     * Этот адрес необходимо указать на сайте ЮKassa
     * в «Настройках магазина» в разделе «Параметры для платежей»
     */

    const YOOMONEY_MODULE_VERSION = '2.1.1';

    /**
     * @var int ЮKassa
     */
    const MODE_YOOKASSA = 1;

    /**
     * @var int ЮMoney
     */
    const MODE_MONEY = 2;

    /**
     * @var int Через что вы будете принимать платежи: ЮKassa или ЮMoney?
     * Укажите нужный MODE из списка выше:
     */
    protected $mode = self::MODE_YOOKASSA;

    protected $apiClient = null;

    /**
     * Только для платежей через ЮMoney: укажите номер кошелька на ЮMoney, в который нужно зачислять платежи
     * @var string Номер кошелька
     */
    protected $yoo_account = '';

    /**
     * @var int Только для ЮKassa: укажите shopId из личного кабинета ЮKassa
     */
    protected $yoo_shopid = 0;

    /**
     * Только для ЮKassa: укажите «Секретный ключ» из личного кабинета ЮKassa
     * @var string Секретный ключ
     */
    protected $yoo_password = '';

    /**
     * Только для ЮKassa: укажите описание платежа.
     * Это описание транзакции, которое пользователь увидит при оплате,
     * а вы — в личном кабинете ЮKassa. Например, «Оплата заказа №72».
     * Чтобы в описание подставлялся номер заказа (как в примере),
     * поставьте на его месте %id% (Оплата заказа №%id%).
     * Ограничение для описания — 128 символов.
     * @var string Описание платежа
     */
    protected $yoo_description = 'Оплата заказа №%id%';

    /**
     * Только для ЮKassa: отправлять в ЮKassa данные для чеков (54-ФЗ)?
     * @var bool True — если нужно, false — если не нужно
     */
    protected $sendCheck = true;

    /**
     * Только для ЮKassa: отправлять в ЮKassa данные для закрывающих чеков (54-ФЗ)?
     * @var bool True — если нужно, false — если не нужно
     */
    protected $sendSecondCheck = true;

    /**
     * Только для ЮKassa: статус заказа, при переходе в который будут отправляться закрывающие чеки
     * Берется из списка статусов заказов (Домой -> Интернет-магазины -> Справочники -> Справочник статусов заказа)
     * @var int По умолчанию - "Доставлено"
     */
    protected $orderStatusSecondCheck = 3;

    /**
     * Направлять пользователя на страницу оплаты сразу после выбора метода оплаты
     * @var bool По умолчанию - false
     */
    protected $autoRedirectToKassa = false;

    /**
     * @var bool Включить логирование.
     */
    protected $enable_logging = false;

    /**
     * Ставки НДС в системе ЮKassa:
     *     1 - Без НДС
     *     2 - 0%
     *     3 - 10%
     *     4 - 20%
     *     5 - Рассчётная ставка 10/110
     *     6 - Рассчётная ставка 20/120
     * @var int Только для ЮKassa: укажите номер из списка выше, который соответствует вашей налоговой ставке
     */
    protected $kassaTaxRateDefault = 4;

    /**
     * Только для ЮKassa. В столбике слева представлены id налоговых ставок, которые есть в вашем магазине. Сопоставьте их с номерами ставок из этого списка:
     *     1 - Без НДС
     *     2 - 0%
     *     3 - 10%
     *     4 - 20%
     *     5 - Расчётная ставка 10/110
     *     6 - Расчётная ставка 20/120
     * @var array Соотнесите ставки в вашем магазине со ставками в ЮKassa
     */
    protected $kassaTaxRates = array(
        2  => 4,
        5  => 4,
        19 => 3,
        20 => 3,
        21 => 1,
    );

    /**
     * Одно из значений перечисления PaymentMode
     * @var string
     */
    protected $defaultPaymentMode = PaymentMode::FULL_PREPAYMENT;

    /**
     * Одно из значений перечисления PaymentSubject
     * @var string
     */
    protected $defaultPaymentSubject = PaymentSubject::COMPOSITE;


    /**
     * @return array
     */
    public static function getValidPaymentMode()
    {
        return array(
            PaymentMode::FULL_PREPAYMENT,
        );
    }

    /**
     * Только для ЮKassa: укажите, как уведомлять об оплате — одним письмом (после подтверждения оплаты от ЮKassa) или двумя письмами (при изменений статуса заказа и после окончательно подтверждения оплаты от Юkassa)
     * @var bool True — если нужно отправлять два письма, false — если нужно отправлять одно письмо
     * @link https://github.com/yoomoney/cms-hostcms/issues/5
     */
    protected $sendChangeStatusEmail = true;

    /**
     * Id валюты, в которой будет производиться расчет суммы:
     *     1 - рубли (RUB)
     *     2 - евро (EUR)
     *     3 - доллары (USD)
     * @var int id валюты
     */
    protected $yoo_currency_id = 1;

    public function __construct(Shop_Payment_System_Model $oShop_Payment_System_Model)
    {
        $oCore_DataBase = Core_DataBase::instance()
                        ->setQueryType(99)
                        ->query(
                            'CREATE TABLE IF NOT EXISTS shop_yoo_order_payments (
                                `id` INT NOT NULL AUTO_INCREMENT,
                                `order_id` INT NOT NULL,
                                `payment_id` VARCHAR(256) NOT NULL,
                                PRIMARY KEY (`id`)
                             )'
                        );
        parent::__construct($oShop_Payment_System_Model);

        Core_Event::attach('Shop_Payment_System_Handler.onBeforeChangedOrder', array($this, 'onChangeOrder'));
    }

    /**
     * @param Shop_Payment_System_Handler $object
     * @param array $args
     * @throws Core_Exception
     */
    public function onChangeOrder($object, $args)
    {
        $mode = $args[0];
        $oShop = $object->getShopOrder();
        $logger = YooMoneyLogger::instance();

        $logger->log('info', 'Mode: ' . $mode);
        if (in_array($mode, array('changeStatusPaid', 'edit', 'apply')))
        {
            $logger->log('info', 'Status before: ' . $object->getShopOrderBeforeAction()->shop_order_status_id . ', Status after: ' . $oShop->shop_order_status_id);
            // Изменился статус заказа
            if ($object->getShopOrderBeforeAction()->shop_order_status_id != $oShop->shop_order_status_id)
            {
                $logger->log('info', 'Status changed!');
                if (!$this->isNeedSecondReceipt($oShop->shop_order_status_id)) {
                    $logger->log('info', 'Second receipt is not need!');
                    return;
                }

                $paymentId = $this->getOrderPaymentId($oShop->id);
                $logger->log('info', 'PaymentId: ' . $paymentId);

                try {
                    if ($lastReceipt = $this->getLastReceipt($paymentId)) {
                        $logger->log('info', 'LastReceipt:' . PHP_EOL . json_encode($lastReceipt->jsonSerialize()));
                    } else {
                        $logger->log('info', 'LastReceipt is empty!');
                        return;
                    }

                    if ($receiptRequest = $this->buildSecondReceipt($lastReceipt, $paymentId, $oShop)) {

                        $logger->log('info', "Second receipt request data: " . PHP_EOL . json_encode($receiptRequest->jsonSerialize()));

                        try {
                            $response = $this->getClient()->createReceipt($receiptRequest);
                        } catch (Exception $e) {
                            $logger->log('error', 'Request second receipt error: ' . $e->getMessage());
                            return;
                        }

                        $logger->log('info', 'Request second receipt result: ' . PHP_EOL . json_encode($response->jsonSerialize()));
                    }
                } catch (Exception $e) {
                    $logger->log('info', 'Error: ' . $e->getMessage());
                    return;
                }

            } else {
                $logger->log('info', 'Status NOT changed!');
            }
        }
    }

    /**
     * Метод, вызываемый в коде настроек ТДС через Shop_Payment_System_Handler::checkBeforeContent($oShop);
     */
    public function checkPaymentBeforeContent()
    {
        $action = Core_Array::getGet('action');
        if (isset($_POST['action']) && isset($_POST['invoiceId']) && isset($_POST['orderNumber']) || isset($_POST['sha1_hash'])) {
            // Получаем ID заказа
            $order_id = isset($_POST['sha1_hash'])
                ? intval(Core_Array::getPost('label'))
                : intval(Core_Array::getPost('orderNumber'));

            $oShop_Order = Core_Entity::factory('Shop_Order')->find($order_id);

            if (!is_null($oShop_Order->id)) {
                header("Content-type: application/xml");

                // Вызов обработчика платежного сервиса
                Shop_Payment_System_Handler::factory($oShop_Order->Shop_Payment_System)
                    ->shopOrder($oShop_Order)
                    ->paymentProcessing();
            }
        } elseif ($action == 'notify') {
            $body = @file_get_contents('php://input');
            $this->log('info', 'Notification: '.$body);
            $callbackParams = json_decode($body, true);
            if (json_last_error()) {
                $this->log('error', 'Parse POST body failed');
                $this->exit400();
            }
            try {
                $fabric = new NotificationFactory();
                $notificationModel = $fabric->factory($callbackParams);
            } catch (\Exception $e) {
                $this->log('error', 'Invalid notification object - ' . $e->getMessage());
                header("HTTP/1.1 400 Bad Request");
                header("Status: 400 Bad Request");
                exit();
            }
            try {
                $paymentResponse = $notificationModel->getObject();
                $client = $this->getClient();
                $paymentId  = $paymentResponse->getId();
                $paymentRow = Core_QueryBuilder::select()
                            ->from('shop_yoo_order_payments')
                            ->where('payment_id', '=', $paymentId)
                            ->limit(1)
                            ->execute()
                            ->asAssoc()
                            ->result();

                if (is_array($paymentRow)) {
                    $paymentRow = $paymentRow[0];
                }

                $order = Core_Entity::factory('Shop_Order')->find($paymentRow['order_id']);
                $this->checkValueIsNotEmpty($order, '404 Not Found',
                    'Order not found. OderId #'.$paymentRow['order_id']);

                $paymentInfo = $client->getPaymentInfo($paymentId);
                $this->checkValueIsNotEmpty($paymentInfo, '404 Not Found',
                    'Payment not found. PaymentId #'.$paymentId);

                $this->log('info', 'Order: '.json_encode($order));
                $this->log('info', 'Payment: '.json_encode($paymentInfo));

                if ($paymentInfo->getStatus() === PaymentStatus::WAITING_FOR_CAPTURE) {
                    $captureRequest = CreateCaptureRequest::builder()->setAmount($paymentInfo->getAmount())->build();
                    $paymentInfo    = $client->capturePayment($captureRequest, $paymentId);
                }

                if ($paymentInfo->getStatus() === PaymentStatus::SUCCEEDED) {
                    $this->completePayment($order);
                    $this->exit200();
                } elseif ($paymentInfo->getStatus() === PaymentStatus::CANCELED) {
                    $this->log('info', 'Payment canceled');
                    $this->exit200();
                } else {
                    $this->log('info', 'Wrong payment status: '.$paymentInfo->getStatus());
                    $this->exit400();
                }
            } catch (Exception $e) {
                $this->log('error', $e->getMessage());
                $this->exit400();
            }
            exit();
        }
    }

    /**
     * Метод, вызываемый в коде ТДС через Shop_Payment_System_Handler::checkAfterContent($oShop);
     * Может быть использован как для получения информации от платежной системы о статусе платежа,
     * так и о выводе информации о результатах оплаты после перенаправления пользователя
     * платежной системой на корзину магазина.
     */
    public function checkPaymentAfterContent()
    {
        $orderId = Core_Array::getGet('order_id');
        $action  = Core_Array::getGet('action');

        if ($orderId && $action == 'return') {
            $order       = Core_Entity::factory('Shop_Order')->find($orderId);
            $oSite_Alias = $order->Shop->Site->getCurrentAlias();
            $sSiteAlias  = !is_null($oSite_Alias) ? $oSite_Alias->name : '';
            $sShopPath   = $order->Shop->Structure->getPath();
            $sHandlerUrl = 'http://'.$sSiteAlias.$sShopPath."cart/?order_id={$order->id}";
            $successUrl  = $sHandlerUrl."&payment=success";
            $failUrl     = $sHandlerUrl."&payment=fail";
            $paymentRow  = Core_QueryBuilder::select()
                         ->from('shop_yoo_order_payments')
                         ->where('order_id', '=', $orderId)
                         ->limit(1)
                         ->execute()
                         ->asAssoc()
                         ->result();

            if (!$paymentRow) {
                $this->log('error', 'Payment not found. OrderId: '.$orderId);
                header('Location: '.$failUrl);
                exit();
            }
            $paymentId = $paymentRow[0]['payment_id'];
            $client    = $this->getClient();
            $paymentInfoResponse = $client->getPaymentInfo($paymentId);

            $this->log('info', 'Order: '.json_encode($order));
            $this->log('info', 'Payment: '.json_encode($paymentInfoResponse));

            if ($paymentInfoResponse->getStatus() === PaymentStatus::WAITING_FOR_CAPTURE) {
                $captureRequest      = CreateCaptureRequest::builder()
                                     ->setAmount($paymentInfoResponse->getAmount())
                                     ->build();
                $paymentInfoResponse = $client->capturePayment($captureRequest, $paymentId);
            }

            if ($paymentInfoResponse->getStatus() === PaymentStatus::SUCCEEDED) {
                $this->completePayment($order);
                header('Location: '.$successUrl);
            } elseif (($paymentInfoResponse->status === PaymentStatus::PENDING) && $paymentInfoResponse->getPaid()) {
                $this->log('info', 'Payment pending and paid');
                header('Location: '.$successUrl);
            } elseif ($paymentInfoResponse->status === PaymentStatus::CANCELED) {
                $this->log('info', 'Payment canceled');
                header('Location: '.$failUrl);
            } else {
                $this->log('error', 'Payment wrong status: '.$paymentInfoResponse->getStatus());
                header('Location: '.$failUrl);
            }
            exit();
        }
    }

    /*
     * Метод, запускающий выполнение обработчика
     */
    public function execute()
    {
        parent::execute();

        $this->printNotification();

        return $this;
    }

    /**
     * Вычисление суммы товаров заказа
     */
    public function getSumWithCoeff()
    {
        if ($this->yoo_currency_id > 0 && $this->_shopOrder->shop_currency_id > 0) {
            $sum = Shop_Controller::instance()->getCurrencyCoefficientInShopCurrency(
                $this->_shopOrder->Shop_Currency,
                Core_Entity::factory('Shop_Currency', $this->yoo_currency_id)
            );
        } else {
            $sum = 0;
        }

        return Shop_Controller::instance()->round($sum * $this->_shopOrder->getAmount());
    }

    /**
     * Обработка ответа платёжного сирвиса
     */
    public function paymentProcessing()
    {
        $this->processResult();

        return true;
    }

    /**
     * Печатает форму отправки запроса на сайт платёжной сервиса
     */
    public function getNotification()
    {
        $sum       = $this->getSumWithCoeff();
        $oSiteuser = Core::moduleIsActive('siteuser')
            ? Core_Entity::factory('Siteuser')->getCurrent()
            : null;

        $oSite_Alias = $this->_shopOrder->Shop->Site->getCurrentAlias();
        $sSiteAlias  = !is_null($oSite_Alias) ? $oSite_Alias->name : '';
        $sShopPath   = $this->_shopOrder->Shop->Structure->getPath();
        $fromUrl     = $sHandlerUrl = 'http://'.$sSiteAlias.$sShopPath."cart";
        $sHandlerUrl = 'http://'.$sSiteAlias.$sShopPath."cart/?order_id={$this->_shopOrder->id}";
        $returnUrl   = $sHandlerUrl."&action=return";
        $successUrl  = $sHandlerUrl."&payment=success";
        $failUrl     = $sHandlerUrl."&payment=fail";
        $oShop_Order = Core_Entity::factory('Shop_Order', $this->_shopOrder->id);
        $oShop_Order->invoice = $this->_shopOrder->id;
        $oShop_Order->save();
        if ($this->mode == self::MODE_YOOKASSA) {
            try {
                $response = $this->createPayment($sum, $returnUrl);
                if ($response) {
                    $confirmationUrl = $response->confirmation->confirmationUrl;
                    $this->writePaymentId($response);
                }

            } catch (Exception $e) {
                $this->log('error', $e->getMessage());
                $errors = 'В процессе создания платежа произошла ошибка.';
            }
        }

        ?>
        <form method="POST" id="frmYooMoney" action="<?php echo $this->getFormUrl() ?>">
            <?php
            if ($this->mode === self::MODE_YOOKASSA) {
                if (isset($errors)) {
                    echo $errors;
                } else {
                    ?>
                    <table border="0" cellspacing="1" align="center" width="80%">
                        <tr>
                            <td align="center">
                                <a href="<?php echo $confirmationUrl?>" id="button-confirm" class="btn btn-primary">Оплатить</a>
                            </td>
                        </tr>
                    </table>
                <?php }
            } elseif ($this->mode === self::MODE_MONEY) { ?>
                <input type="hidden" name="receiver" value="<?php echo htmlspecialchars($this->yoo_account); ?>">
                <input type="hidden" name="formcomment" value="<?php echo htmlspecialchars($sSiteAlias); ?>">
                <input type="hidden" name="short-dest" value="<?php echo htmlspecialchars($sSiteAlias); ?>">
                <input type="hidden" name="writable-targets" value="false">
                <input type="hidden" name="comment-needed" value="true">
                <input type="hidden" name="label" value="<?php echo $this->_shopOrder->id; ?>">
                <input type="hidden" name="quickpay-form" value="shop">
                <input type="hidden" name="successUrl" value="<?php echo htmlspecialchars($successUrl); ?>">

                <input type="hidden" name="targets" value="Заказ <?php echo $this->_shopOrder->id; ?>">
                <input type="hidden" name="sum" value="<?php echo $sum; ?>" data-type="number">
                <input type="hidden" name="comment"
                       value="<?php echo htmlspecialchars($this->_shopOrder->description); ?>">
                <input type="hidden" name="need-fio" value="true">
                <input type="hidden" name="need-email" value="true">
                <input type="hidden" name="need-phone" value="false">
                <input type="hidden" name="need-address" value="false">
                <input type="submit" name="BuyButton" id="button-confirm" value="Оплатить">
            <?php }?>
        </form>
        <script type="text/javascript">
            <?php if ($this->autoRedirectToKassa) : ?>
            const paymentButton = document.getElementById('button-confirm');
            if (paymentButton) {
                paymentButton.click();
            }
            <?php endif; ?>
        </script>
        <?php
    }

    public function getInvoice()
    {
        return $this->getNotification();
    }

    protected function _processOrder()
    {
        parent::_processOrder();

        if (method_exists($this, 'setMailSubjects')) {
            $this->setMailSubjects();
        }
        // Установка XSL-шаблонов в соответствии с настройками в узле структуры
        $this->setXSLs();
        // Отправка писем администраторам и пользователю
        $this->send();

        return $this;
    }

    /**
     * @param $response
     *
     * @return Core_DataBase
     */
    protected function writePaymentId($response)
    {
        $paymentRow = Core_QueryBuilder::select()
                    ->from('shop_yoo_order_payments')
                    ->where('order_id', '=', $this->_shopOrder->id)
                    ->limit(1)
                    ->execute()
                    ->asAssoc()
                    ->result();

        if ($paymentRow) {
            $result = Core_QueryBuilder::update('shop_yoo_order_payments')
                    ->columns(array('payment_id' => $response->getId()))
                    ->where('order_id', '=', $this->_shopOrder->id)
                    ->execute();
        } else {
            $result = Core_QueryBuilder::insert('shop_yoo_order_payments')
                    ->columns('order_id', 'payment_id')
                    ->values($this->_shopOrder->id, $response->getId())
                    ->execute();
        }

        return $result;
    }

    /**
     * @return string
     */
    private function getFormUrl()
    {
        $sUrl = 'https://';

        return $this->mode === self::MODE_YOOKASSA
            ? ''
            : $sUrl.'yoomoney.ru/quickpay/confirm.xml';
    }

    /**
     * @param string $tpl
     * @param Shop_Order_Model $order
     *
     * @return string
     */
    private function parsePlaceholders($tpl, $order)
    {
        $replace = array(
            '%order_id%' => $order->id,
        );
        foreach ($order->toArray() as $key => $value) {
            if (is_scalar($value)) {
                $replace['%'.$key.'%'] = $value;
            }
        }

        return strtr($tpl, $replace);
    }

    private function checkSign($callbackParams)
    {
        if ($this->mode === self::MODE_YOOKASSA) {
            $string = $callbackParams['action'].';'.$callbackParams['orderSumAmount'].';'
                .$callbackParams['orderSumCurrencyPaycash'].';'.$callbackParams['orderSumBankPaycash'].';'
                .$callbackParams['shopId'].';'.$callbackParams['invoiceId'].';'
                .$callbackParams['customerNumber'].';'.$this->yoo_password;

            return strtoupper($callbackParams['md5']) == strtoupper(md5($string));
        } else {
            $string = $callbackParams['notification_type'].'&'.$callbackParams['operation_id'].'&'
                .$callbackParams['amount'].'&'.$callbackParams['currency'].'&'
                .$callbackParams['datetime'].'&'.$callbackParams['sender'].'&'
                .$callbackParams['codepro'].'&'.$this->yoo_password.'&'.$callbackParams['label'];

            $check = (sha1($string) == $callbackParams['sha1_hash']);
            if (!$check) {
                header('HTTP/1.0 401 Unauthorized');

                return false;
            }

            return true;
        }
    }

    private function sendCode($callbackParams, $code, $message = '')
    {
        if ($this->mode != self::MODE_YOOKASSA) {
            return;
        }

        $invoiceId = isset($callbackParams['invoiceId']) ? $callbackParams['invoiceId'] : '';
        header("Content-type: text/xml; charset=utf-8");
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
            <'.$callbackParams['action'].'Response performedDatetime="'.date("c").'" code="'.$code
            .'" invoiceId="'.$invoiceId.'" shopId="'.$this->yoo_shopid
            .'" techmessage="'.$message.'"/>';
        echo $xml;
        die();
    }

    /**
     * Оплачивает заказ
     */
    private function processResult()
    {
        if ($this->checkSign($_POST)) {
            if (isset($_POST['action']) || ($this->mode !== self::MODE_YOOKASSA)) {
                $order_id = intval(Core_Array::getPost(isset($_POST["label"]) ? "label" : "orderNumber"));
                if ($order_id > 0) {
                    $oShop_Order = $this->_shopOrder;

                    $sHostcmsSum = sprintf("%.2f", $this->getSumWithCoeff());
                    $sYoomoneySum  = Core_Array::getRequest('orderSumAmount', '');

                    if ($sHostcmsSum == $sYoomoneySum) {
                        if ($_POST['action'] == 'paymentAviso') {
                            $this->shopOrder($oShop_Order)->shopOrderBeforeAction(clone $oShop_Order);

                            $oShop_Order->system_information = "Заказ оплачен через сервис ЮKassa.\n";
                            $oShop_Order->paid();

                            if (method_exists($this, 'setMailSubjects')) {
                                $this->setMailSubjects();
                            }
                            $this->setXSLs();
                            $this->sendEmail($this->sendChangeStatusEmail);

                            ob_start();
                            $this->changedOrder('changeStatusPaid');
                            ob_get_clean();
                        }
                    } else {
                        $this->sendCode($_POST, 100, 'Bad amount');
                    }
                }
                $this->sendCode($_POST, 0, 'Order completed.');
            } else {
                $this->sendCode($_POST, 0, 'Order is exist.');
            }
        } else {
            $this->sendCode($_POST, 1, 'md5 bad');
        }
    }

    /**
     * @param bool $sendToAdmin
     *
     * @return Shop_Payment_System_Handler
     * @throws Core_Exception
     */
    protected function sendEmail($sendToAdmin)
    {
        if ($sendToAdmin) {
            return $this->send();
        }

        Core_Event::notify('Shop_Payment_System_Handler.onBeforeSend', $this);
        if (is_null($this->_shopOrder)) {
            throw new Core_Exception('send(): shopOrder is empty.');
        }
        $oShopOrder = $this->_shopOrder;
        $oShop      = $oShopOrder->Shop;
        if ($oShop->send_order_email_user) {
            $oCore_Mail_Siteuser = $this->getSiteuserEmail();
            $this->sendSiteuserEmail($oCore_Mail_Siteuser);
        }
        Core_Event::notify('Shop_Payment_System_Handler.onAfterSend', $this);

        return $this;
    }

    /**
     * @param $sum
     *
     * @param $returnUrl
     * @return CreatePaymentResponse
     * @throws ApiException
     * @throws BadApiRequestException
     * @throws ForbiddenException
     * @throws InternalServerError
     * @throws NotFoundException
     * @throws ResponseProcessingException
     * @throws TooManyRequestsException
     * @throws UnauthorizedException
     */
    protected function createPayment($sum, $returnUrl)
    {
        $client = $this->getClient();

        $builder = CreatePaymentRequest::builder()
                ->setAmount($sum)
                ->setPaymentMethodData('')
                ->setCapture(true)
                ->setDescription($this->createDescription())
                ->setConfirmation(
                    array(
                        'type'      => ConfirmationType::REDIRECT,
                        'returnUrl' => $returnUrl,
                    )
                )
                ->setMetadata(array(
                    'cms_name'       => 'yoo_api_hostcms',
                    'module_version' => self::YOOMONEY_MODULE_VERSION,
                ));

        if ($this->sendCheck) {
            $oShop_Order     = Core_Entity::factory('Shop_Order', $this->_shopOrder->id);
            $aShopOrderItems = $oShop_Order->Shop_Order_Items->findAll();

            $email = isset($this->_shopOrder->email) ? $this->_shopOrder->email : '';
            $phone = isset($this->_shopOrder->phone) ? $this->_shopOrder->phone : '';
            if (!empty($email)) {
                $builder->setReceiptEmail($email);
            }
            if (!empty($phone)) {
                $builder->setReceiptPhone($phone);
            }

            $disc = 0;
            $osum = 0;
            foreach ($aShopOrderItems as $kk => $item) {
                if ($item->price < 0) {
                    $disc -= $item->getAmount();
                    unset($aShopOrderItems[$kk]);
                } else {
                    if ($item->shop_item_id) {
                        $osum += $item->getAmount();
                    }
                }
            }

            unset($item);
            $disc = abs($disc) / $osum;
            foreach ($aShopOrderItems as $item) {
                $tax_id = null;
                if ($item->shop_item_id) {
                    $tax_id = $item->Shop_Item->shop_tax_id;
                }
                $tax    = Core_Array::get($this->kassaTaxRates, $tax_id, $this->kassaTaxRateDefault);
                $amount = $item->getPrice() * ($item->shop_item_id ? 1 - $disc : 1);
                $builder->addReceiptItem(
                    $item->name,
                    $amount,
                    $item->quantity,
                    $tax,
                    $this->defaultPaymentMode,
                    $this->defaultPaymentSubject
                );
            }
        }

        $createPaymentRequest = $builder->build();
        $receipt              = $createPaymentRequest->getReceipt();
        if ($receipt instanceof Receipt) {
            $receipt->normalize($createPaymentRequest->getAmount());
        }

        $response = $client->createPayment($createPaymentRequest);

        return $response;
    }

    /**
     * @param $order
     * @throws Core_Exception
     */
    private function completePayment($order)
    {
        $this->log('info', 'Payment completed');
        $this->shopOrder($order)->shopOrderBeforeAction(clone $order);

        $order->system_information = "Заказ оплачен через сервис ЮKassa.\n";
        $order->paid();

        if (method_exists($this, 'setMailSubjects')) {
            $this->setMailSubjects();
        }
        $this->setXSLs();
        $this->sendEmail($this->sendChangeStatusEmail);

        ob_start();
        $this->changedOrder('changeStatusPaid');
        ob_get_clean();
    }

    /**
     * @param string $level
     * @param string $message
     */
    private function log($level, $message)
    {
        if ($this->enable_logging) {
            YooMoneyLogger::instance()->log($level, $message);
        }
    }

    /**
     * @param mixed $value
     * @param string $status
     * @param string $logMessage
     */
    function checkValueIsNotEmpty($value, $status, $logMessage)
    {
        if (!$value) {
            $this->log('error', $logMessage);
            header('HTTP/1.1 '.$status);
            header('Status: '.$status);
            exit();
        }
    }

    private function exit200()
    {
        header('HTTP/1.1 200 OK');
        header('Status: 200 OK');
        exit();
    }

    private function exit400()
    {
        header('HTTP/1.1 400 Bad Request');
        header('Status: 400 Bad Request');
        exit();
    }

    /**
     * @return string
     */
    private function createDescription()
    {
        $descriptionTemplate = $this->yoo_description;

        $replace  = array();
        $patterns = explode('%', $descriptionTemplate);
        foreach ($patterns as $pattern) {
            $value = null;
            if (isset($this->getShopOrder()->$pattern)) {
                $value = $this->getShopOrder()->$pattern;
            } else {
                $method = 'get'.ucfirst($pattern);
                if (method_exists($this->getShopOrder(), $method)) {
                    $value = $this->getShopOrder()->{$method}();
                }
            }
            if (!is_null($value) && is_scalar($value)) {
                $replace['%'.$pattern.'%'] = $value;
            }
        }

        $description = strtr($descriptionTemplate, $replace);

        return (string)mb_substr($description, 0, Payment::MAX_LENGTH_DESCRIPTION);
    }

    /**
     * @return Client
     */
    private function getClient()
    {
        if (!$this->apiClient) {
            $this->apiClient = new Client();
            $userAgent = $this->apiClient->getApiClient()->getUserAgent();
            $userAgent->setCms('HostCMS', Informationsystem_Module::factory('informationsystem')->version);
            $userAgent->setModule('PaymentGateway', self::YOOMONEY_MODULE_VERSION);
            $this->apiClient->setAuth($this->yoo_shopid, $this->yoo_password);
            if ($this->enable_logging) {
                $this->apiClient->setLogger(YooMoneyLogger::instance());
            }
        }

        return $this->apiClient;
    }

    /**
     * @param $order_status_id
     * @return bool
     */
    private function isNeedSecondReceipt($order_status_id)
    {
        return ($this->sendCheck && $this->sendSecondCheck && $this->orderStatusSecondCheck == $order_status_id);
    }

    /**
     * @param int $order_id
     * @return string|null
     * @throws Core_Exception
     */
    private function getOrderPaymentId($order_id)
    {
        $result = null;
        $paymentRow = Core_QueryBuilder::select()
            ->from('shop_yoo_order_payments')
            ->where('order_id', '=', $order_id)
            ->orderBy('id', 'DESC')
            ->limit(1)
            ->execute()
            ->asAssoc()
            ->result();

        if (is_array($paymentRow) && !empty($paymentRow[0]['payment_id'])) {
            $result = $paymentRow[0]['payment_id'];
        }
        return $result;
    }

    /**
     * @param $paymentId
     * @return mixed|ReceiptResponseInterface
     * @throws ApiException
     * @throws BadApiRequestException
     * @throws ExtensionNotFoundException
     * @throws ForbiddenException
     * @throws InternalServerError
     * @throws NotFoundException
     * @throws ResponseProcessingException
     * @throws TooManyRequestsException
     * @throws UnauthorizedException
     */
    private function getLastReceipt($paymentId)
    {
        $receipts = $this->getClient()->getReceipts(array('payment_id' => $paymentId))->getItems();

        return array_pop($receipts);
    }

    /**
     * @param ReceiptResponseInterface $lastReceipt
     * @param string $paymentId
     * @param Shop_Order_Model $order
     * @return CreatePostReceiptRequest|null
     */
    private function buildSecondReceipt($lastReceipt, $paymentId, $order)
    {
        if ($lastReceipt instanceof ReceiptResponseInterface) {
            if ($lastReceipt->getType() === "refund") {
                return null;
            }

            $resendItems = $this->getResendItems($lastReceipt->getItems());

            if (count($resendItems['items']) < 1) {
                $this->log('info', 'Second receipt is not required');
                return null;
            }

            try {
                $customer = $this->getReceiptCustomer($order);

                if (empty($customer)) {
                    $this->log('error', 'Need customer phone or email for second receipt');
                    return null;
                }

                $receiptBuilder = CreatePostReceiptRequest::builder();
                $receiptBuilder->setObjectId($paymentId)
                    ->setType(ReceiptType::PAYMENT)
                    ->setItems($resendItems['items'])
                    ->setSettlements(array(
                        new Settlement(array(
                            'type' => 'prepayment',
                            'amount' => array(
                                'value' => $resendItems['amount'],
                                'currency' => 'RUB',
                            ),
                        )),
                    ))
                    ->setCustomer($customer)
                    ->setSend(true);

                return $receiptBuilder->build();
            } catch (Exception $e) {
                $this->log('error', $e->getMessage() . '. Property name: '. $e->getProperty());
            }
        }

        return null;
    }

    /**
     * @param Shop_Order_Model $order
     * @return bool|ReceiptCustomer
     */
    private function getReceiptCustomer($order)
    {
        $customerData = array();

        if (!empty($order->email)) {
            $customerData['email'] = $order->email;
        }

        if (!empty($order->phone)) {
            $customerData['phone'] = $order->phone;
        }

        if (!empty($order->tin)) {
            $customerData['inn'] = $order->tin;
        }

        $userName = array();
        if (!empty($order->surname))    $userName[] = $order->surname;
        if (!empty($order->name))       $userName[] = $order->name;
        if (!empty($order->patronymic)) $userName[] = $order->patronymic;
        if ($userFullName = implode(' ', $userName)) {
            $customerData['full_name'] = $userFullName;
        }

        return new ReceiptCustomer($customerData);
    }

    /**
     * @param ReceiptResponseItemInterface[] $items
     *
     * @return array
     */
    private function getResendItems($items)
    {
        $result = array(
            'items'  => array(),
            'amount' => 0,
        );

        foreach ($items as $item) {
            if ($this->isNeedResendItem($item->getPaymentMode())) {
                $item->setPaymentMode(PaymentMode::FULL_PAYMENT);
                $result['items'][] = new ReceiptItem($item->jsonSerialize());
                $result['amount'] += $item->getAmount() / 100.0;
            }
        }

        return $result;
    }

    /**
     * @param string $paymentMode
     *
     * @return bool
     */
    private function isNeedResendItem($paymentMode)
    {
        return in_array($paymentMode, self::getValidPaymentMode());
    }
}


class YooMoneyLogger extends Core_Log
{
    static public function instance()
    {
        return new self();
    }

    public function getLogName($date)
    {
        return $this->_logDir.DIRECTORY_SEPARATOR.'yoo_payment_log_'.date('d_m_Y',
                Core_Date::sql2timestamp($date)).'.log';
    }

    public function log($level, $message, $context = null)
    {
        $this->clear()
            ->notify(false)
            ->status($this->convertLevelToType($level))
            ->write($message);
    }

    /**
     * Convert standard log level to HostCMS type
     * @param string $level
     * @return int
     */
    private function convertLevelToType ($level)
    {
        $type = self::$MESSAGE;

        switch ($level) {
            case 'info':
                $type = self::$MESSAGE;
                break;
            case 'debug':
                $type = self::$SUCCESS;
                break;
            case 'notice':
                $type = self::$NOTICE;
                break;
            case 'warn':
                $type = self::$WARNING;
                break;
            case 'error':
                $type = self::$ERROR;
                break;
        }

        return $type;
    }
}
