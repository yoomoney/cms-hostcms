<?php

/**
 * Яндекс.Касса
 * Версия 1.2.0
 *
 * Лицензионный договор:
 * Любое использование Вами программы означает полное и безоговорочное принятие Вами условий лицензионного договора,
 * размещенного по адресу https://money.yandex.ru/doc.xml?id=527132 (далее – «Лицензионный договор»).
 * Если Вы не принимаете условия Лицензионного договора в полном объёме,
 * Вы не имеете права использовать программу в каких-либо целях.
 */
use YandexCheckout\Client;
use YandexCheckout\Common\Exceptions\ApiException;
use YandexCheckout\Common\Exceptions\BadApiRequestException;
use YandexCheckout\Common\Exceptions\ExtensionNotFoundException;
use YandexCheckout\Common\Exceptions\ForbiddenException;
use YandexCheckout\Common\Exceptions\InternalServerError;
use YandexCheckout\Common\Exceptions\NotFoundException;
use YandexCheckout\Common\Exceptions\ResponseProcessingException;
use YandexCheckout\Common\Exceptions\TooManyRequestsException;
use YandexCheckout\Common\Exceptions\UnauthorizedException;
use YandexCheckout\Model\ConfirmationType;
use YandexCheckout\Model\Notification\NotificationFactory;
use YandexCheckout\Model\Payment;
use YandexCheckout\Model\PaymentStatus;
use YandexCheckout\Model\Receipt;
use YandexCheckout\Model\Receipt\PaymentMode;
use YandexCheckout\Model\Receipt\PaymentSubject;
use YandexCheckout\Model\ReceiptCustomer;
use YandexCheckout\Model\ReceiptItem;
use YandexCheckout\Model\ReceiptType;
use YandexCheckout\Model\Settlement;
use YandexCheckout\Request\Payments\CreatePaymentRequest;
use YandexCheckout\Request\Payments\CreatePaymentResponse;
use YandexCheckout\Request\Payments\Payment\CreateCaptureRequest;
use YandexCheckout\Request\Receipts\CreatePostReceiptRequest;
use YandexCheckout\Request\Receipts\ReceiptResponseInterface;
use YandexCheckout\Request\Receipts\ReceiptResponseItemInterface;

require_once CMS_FOLDER.'yandex-money'.DIRECTORY_SEPARATOR.'autoload.php';

class Shop_Payment_System_HandlerXX extends Shop_Payment_System_Handler
{
    /**
     * Адрес для уведомлений: https://[ваш-сайт]/shop/cart/?action=notify
     *
     * Этот адрес необходимо указать на сайте Яндекс.Кассы
     * в «Настройках магазина» в разделе «Параметры для платежей»
     */

    const YAMONEY_MODULE_VERSION = '1.2.0';

    /**
     * @var int Яндекс.Касса
     */
    const MODE_KASSA = 1;

    /**
     * @var int Яндекс.Деньги
     */
    const MODE_MONEY = 2;

    /**
     * @var int Яндекс.Платежка
     */
    const MODE_BILLING = 3;

    /**
     * @var int Через что вы будете принимать платежи: Яндекс.Касса, Яндекс.Деньги или Яндекс.Платежка?
     * Укажите нужный MODE из списка выше:
     */
    protected $mode = self::MODE_KASSA;

    protected $apiClient = null;

    /**
     * Только для платежей через Яндекс.Деньги: укажите номер кошелька на Яндексе, в который нужно зачислять платежи
     * @var string Номер кошелька
     */
    protected $ym_account = '';

    /**
     * @var int Только для Яндекс.Кассы: укажите shopId из личного кабинета Яндекс.Кассы
     */
    protected $ym_shopid = 0;

    /**
     * Только для Яндекс.Кассы: укажите «Секретный ключ» из личного кабинета Яндекс.Кассы
     * @var string Секретный ключ
     */
    protected $ym_password = '';

    /**
     * Только для Яндекс.Кассы: укажите описание платежа.
     * Это описание транзакции, которое пользователь увидит при оплате,
     * а вы — в личном кабинете Яндекс.Кассы. Например, «Оплата заказа №72».
     * Чтобы в описание подставлялся номер заказа (как в примере),
     * поставьте на его месте %id% (Оплата заказа №%id%).
     * Ограничение для описания — 128 символов.
     * @var string Описание платежа
     */
    protected $ym_description = 'Оплата заказа №%id%';

    /**
     * Только для Яндекс.Кассы: отправлять в Яндекс.Кассу данные для чеков (54-ФЗ)?
     * @var bool True — если нужно, false — если не нужно
     */
    protected $sendCheck = true;

    /**
     * Только для Яндекс.Кассы: отправлять в Яндекс.Кассу данные для закрывающих чеков (54-ФЗ)?
     * @var bool True — если нужно, false — если не нужно
     */
    protected $sendSecondCheck = true;

    /**
     * Только для Яндекс.Кассы: статус заказа, при переходе в который будут отправляться закрывающие чеки
     * Берется из списка статусов заказов (Домой -> Интернет-магазины -> Справочники -> Справочник статусов заказа)
     * @var int По умолчанию - "Доставлено"
     */
    protected $orderStatusSecondCheck = 3;

    /**
     * @var bool Включить логирование.
     */
    protected $enable_logging = false;

    /**
     * Ставки НДС в системе Яндекс.Кассы:
     *     1 - Без НДС
     *     2 - 0%
     *     3 - 10%
     *     4 - 20%
     *     5 - Рассчётная ставка 10/110
     *     6 - Рассчётная ставка 20/120
     * @var int Только для Яндекс.Кассы: укажите номер из списка выше, который соответствует вашей налоговой ставке
     */
    protected $kassaTaxRateDefault = 4;

    /**
     * Только для Яндекс.Кассы. В столбике слева представлены id налоговых ставок, которые есть в вашем магазине. Сопоставьте их с номерами ставок из этого списка:
     *     1 - Без НДС
     *     2 - 0%
     *     3 - 10%
     *     4 - 20%
     *     5 - Расчётная ставка 10/110
     *     6 - Расчётная ставка 20/120
     * @var array Соотнесите ставки в вашем магазине со ставками в Яндекс.Кассе
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
     * Только для Яндекс.Кассы: укажите, как уведомлять об оплате — одним письмом (после подтверждения оплаты от Яндекс.Кассы) или двумя письмами (при изменений статуса заказа и после окончательно подтверждения оплаты от Кассы)
     * @var bool True — если нужно отправлять два письма, false — если нужно отправлять одно письмо
     * @link https://github.com/yandex-money/yandex-money-cms-hostcms/issues/5
     */
    protected $sendChangeStatusEmail = true;

    /**
     *Только для Яндекс.Платежки: укажите ID формы
     * @var string ID формы
     */
    protected $billingId = '';

    /**
     * Только для Яндекс.Платежки: если нужно, отредактируйте назначение платежа. Напишите в нем всё, что поможет отличить заказ, который оплатили через Платежку — эта информация будет в платежном поручении
     * @var string Назначение платежа
     */
    protected $billingPurpose = 'Номер заказа %order_id% Оплата через Яндекс.Платежку';

    /**
     * Только для Яндекс.Платежки:  укажите, какой статус соответствует заказу, для оплаты которого выбрали Платежку. Статус должен показать, что результат платежа неизвестен: заплатил клиент или нет, вы можете узнать только из уведомления на электронной почте или в своем банке
     * @var int Айди статуса заказа
     */
    protected $billingChangeStatus = 0;

    /**
     * Id валюты, в которой будет производиться расчет суммы:
     *     1 - рубли (RUB)
     *     2 - евро (EUR)
     *     3 - доллары (USD)
     * @var int id валюты
     */
    protected $ym_currency_id = 1;

    public function __construct(Shop_Payment_System_Model $oShop_Payment_System_Model)
    {
        $oCore_DataBase = Core_DataBase::instance()
                        ->setQueryType(99)
                        ->query(
                            'CREATE TABLE IF NOT EXISTS shop_ym_order_payments (
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
        $logger = YandexCheckoutLogger::instance();

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
                            ->from('shop_ym_order_payments')
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
                         ->from('shop_ym_order_payments')
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
        if ($this->ym_currency_id > 0 && $this->_shopOrder->shop_currency_id > 0) {
            $sum = Shop_Controller::instance()->getCurrencyCoefficientInShopCurrency(
                $this->_shopOrder->Shop_Currency,
                Core_Entity::factory('Shop_Currency', $this->ym_currency_id)
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
        if ($this->mode == self::MODE_KASSA) {
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
        <form method="POST" id="frmYandexMoney" action="<?php echo $this->getFormUrl() ?>">
            <?php
            if ($this->mode === self::MODE_KASSA) {
                if (isset($errors)) {
                    echo $errors;
                } else {
                    ?>
                    <table border="0" cellspacing="1" align="center" width="80%">
                        <tr>
                            <td align="center">
                                <a href="<?= $confirmationUrl ?>"><img width="165" height="76"
                                                                       src="data:image/png;base64,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"/></a>
                            </td>
                        </tr>
                    </table>
                <?php }
            } elseif ($this->mode === self::MODE_MONEY) { ?>
                <input type="hidden" name="receiver" value="<?php echo htmlspecialchars($this->ym_account); ?>">
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
                <input type="submit" name="BuyButton" value="Оплатить">
            <?php } elseif ($this->mode === self::MODE_BILLING) {
                $narrative = $this->parsePlaceholders($this->billingPurpose, $this->_shopOrder);
                $tmp       = array();
                if (!empty($this->_shopOrder->surname)) {
                    $tmp[] = $this->_shopOrder->surname;
                }
                if (!empty($this->_shopOrder->name)) {
                    $tmp[] = $this->_shopOrder->name;
                }
                if (!empty($this->_shopOrder->patronymic)) {
                    $tmp[] = $this->_shopOrder->patronymic;
                }
                $fio = implode(' ', $tmp);
                ?>
                <input type="hidden" name="formId" value="<?php echo htmlspecialchars($this->billingId); ?>"/>
                <input type="hidden" name="narrative" value="<?php echo htmlspecialchars($narrative); ?>"/>
                <input type="hidden" name="quickPayVersion" value="2"/>

                <table border="0" cellspacing="1" align="center" width="80%" bgcolor="#CCCCCC">
                    <tr>
                        <td>ФИО плательщика</td>
                        <td>
                            <input type="text" name="fio" value="<?php echo htmlspecialchars($fio); ?>"
                                   id="ym-billing-fio"/>
                            <div id="ym-billing-fio-error" style="display:none;">Укажите фамилию, имя и отчество
                                плательщика
                            </div>
                        </td>
                    </tr>
                    <tr bgcolor="#FFFFFF">
                        <td width="490"></td>
                        <td width="48"><input type="submit" name="BuyButton" id='button-confirm' value="Оплатить"></td>
                    </tr>
                </table>
            <?php } ?>
        </form>
        <script type="text/javascript">
            document.getElementById('button-confirm').addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                <?php if ($this->mode === self::MODE_BILLING) { ?>
                var field = document.getElementById('ym-billing-fio');
                var error = document.getElementById('ym-billing-fio-error');
                var parts = field.value.trim().split(/\s+/);
                if (parts.length == 3) {
                    error.style.display = 'none';
                    field.value = parts.join(' ');
                    document.getElementById('frmYandexMoney').submit();
                } else {
                    error.style.display = 'block';
                }
                <?php } else { ?>
                document.getElementById('frmYandexMoney').submit();
                <?php } ?>
            }, false);
        </script>
        <?php
        if ($this->mode === self::MODE_BILLING && $this->billingChangeStatus > 0) {
            $oShop_Order->shop_order_status_id = $this->billingChangeStatus;
            $oShop_Order->save();
        }
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
                    ->from('shop_ym_order_payments')
                    ->where('order_id', '=', $this->_shopOrder->id)
                    ->limit(1)
                    ->execute()
                    ->asAssoc()
                    ->result();

        if ($paymentRow) {
            $result = Core_QueryBuilder::update('shop_ym_order_payments')
                    ->columns(array('payment_id' => $response->getId()))
                    ->where('order_id', '=', $this->_shopOrder->id)
                    ->execute();
        } else {
            $result = Core_QueryBuilder::insert('shop_ym_order_payments')
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
        if ($this->mode === self::MODE_BILLING) {
            return 'https://money.yandex.ru/fastpay/confirm';
        }
        $sUrl = 'https://';

        return $this->mode === self::MODE_KASSA
            ? ''
            : $sUrl.'money.yandex.ru/quickpay/confirm.xml';
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
        if ($this->mode === self::MODE_KASSA) {
            $string = $callbackParams['action'].';'.$callbackParams['orderSumAmount'].';'
                .$callbackParams['orderSumCurrencyPaycash'].';'.$callbackParams['orderSumBankPaycash'].';'
                .$callbackParams['shopId'].';'.$callbackParams['invoiceId'].';'
                .$callbackParams['customerNumber'].';'.$this->ym_password;

            return strtoupper($callbackParams['md5']) == strtoupper(md5($string));
        } else {
            $string = $callbackParams['notification_type'].'&'.$callbackParams['operation_id'].'&'
                .$callbackParams['amount'].'&'.$callbackParams['currency'].'&'
                .$callbackParams['datetime'].'&'.$callbackParams['sender'].'&'
                .$callbackParams['codepro'].'&'.$this->ym_password.'&'.$callbackParams['label'];

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
        if ($this->mode != self::MODE_KASSA) {
            return;
        }

        $invoiceId = isset($callbackParams['invoiceId']) ? $callbackParams['invoiceId'] : '';
        header("Content-type: text/xml; charset=utf-8");
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
            <'.$callbackParams['action'].'Response performedDatetime="'.date("c").'" code="'.$code
            .'" invoiceId="'.$invoiceId.'" shopId="'.$this->ym_shopid
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
            if (isset($_POST['action']) || ($this->mode !== self::MODE_KASSA)) {
                $order_id = intval(Core_Array::getPost(isset($_POST["label"]) ? "label" : "orderNumber"));
                if ($order_id > 0) {
                    $oShop_Order = $this->_shopOrder;

                    $sHostcmsSum = sprintf("%.2f", $this->getSumWithCoeff());
                    $sYandexSum  = Core_Array::getRequest('orderSumAmount', '');

                    if ($sHostcmsSum == $sYandexSum) {
                        if ($_POST['action'] == 'paymentAviso') {
                            $this->shopOrder($oShop_Order)->shopOrderBeforeAction(clone $oShop_Order);

                            $oShop_Order->system_information = "Заказ оплачен через сервис Яндекс.Касса.\n";
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
                    'cms_name'       => 'ya_api_hostcms',
                    'module_version' => self::YAMONEY_MODULE_VERSION,
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

        $order->system_information = "Заказ оплачен через сервис Яндекс.Касса.\n";
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
            YandexCheckoutLogger::instance()->log($level, $message);
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
        $descriptionTemplate = $this->ym_description;

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
            $userAgent->setModule('PaymentGateway', self::YAMONEY_MODULE_VERSION);
            $this->apiClient->setAuth($this->ym_shopid, $this->ym_password);
            if ($this->enable_logging) {
                $this->apiClient->setLogger(YandexCheckoutLogger::instance());
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
            ->from('shop_ym_order_payments')
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


class YandexCheckoutLogger extends Core_Log
{
    static public function instance()
    {
        return new self();
    }

    public function getLogName($date)
    {
        return $this->_logDir.DIRECTORY_SEPARATOR.'yc_payment_log_'.date('d_m_Y',
                Core_Date::sql2timestamp($date)).'.log';
    }

    public function log($level = 'info', $message, $context = null)
    {
        $this->clear()
            ->notify(false)
            ->status(Core_Log::$MESSAGE)
            ->write($message);
    }
}
