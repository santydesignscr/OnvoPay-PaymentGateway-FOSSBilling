<?php

declare(strict_types=1);

/**
 * ONVO (OnvoPay) payment gateway adapter for FOSSBilling — embedded (modal) integration.
 *
 * This adapter never redirects the buyer to a hosted/payment-link page. It
 * uses ONVO's official Web SDK to render a card form inside a modal on the
 * invoice page itself, backed by a server-created Payment Intent, exactly
 * following ONVO's documented flow:
 *
 *   - https://docs.onvopay.com/authentication
 *   - https://docs.onvopay.com/payments/payment-intents
 *   - https://docs.onvopay.com/integrations/sdk   (Web SDK)
 *   - https://docs.onvopay.com/webhooks
 *   - https://docs.onvopay.com/reference/errors
 *
 * How it works
 * ------------
 * 1. getHtml() creates an ONVO Customer (optional, best effort) and a
 *    Payment Intent (POST /v1/payment-intents) for the invoice total, then
 *    returns a page containing a "Pay" button that opens a modal.
 * 2. Inside the modal, ONVO's Web SDK (`https://sdk.onvopay.com/sdk.js`) is
 *    loaded and `onvo.pay({...}).render('#onvo-container')` draws ONVO's own
 *    card form using the Publishable Key. The SDK collects the card,
 *    creates the payment method and confirms the Payment Intent internally
 *    (including any 3-D Secure challenge) without ever leaving the page.
 * 3. On `onSuccess`, the browser pings this gateway's IPN endpoint
 *    (notify_url) with the Payment Intent id so the invoice can be marked
 *    as paid immediately, then reloads the invoice page.
 * 4. Independently — and this is the authoritative path — ONVO also POSTs
 *    `payment-intent.succeeded` / `.failed` webhooks (JSON body) to the same
 *    IPN endpoint, authenticated with the `X-Webhook-Secret` header. This
 *    covers cases where step 3 never reaches the server (closed tab,
 *    network drop, deferred/3DS-interrupted flows, etc.).
 *
 * Both paths converge on settlePaymentIntent(), which NEVER trusts the
 * caller: it always re-fetches the Payment Intent from ONVO's API with the
 * Secret Key before crediting anything, checks it against the invoice
 * total, and is idempotent (safe to call more than once for the same
 * payment — an already-paid invoice is simply left alone).
 *
 * Required setup in ONVO's Dashboard (Developers > Webhooks)
 * ------------------------------------------------------------
 *   URL      -> the "Notify URL" / "Callback URL" FOSSBilling shows for this
 *               gateway under Configuration > Payment gateways > ONVO.
 *   Events   -> payment-intent.succeeded, payment-intent.failed (recommended)
 *   Secret   -> copy the generated `webhook_secret_...` value into this
 *               gateway's "Webhook Secret" field (or "Test Webhook Secret"
 *               while Test mode is enabled).
 *
 * Supported currencies (per ONVO's API): USD, CRC, GTQ, NIO, PAB, PEN, MXN,
 * COP, HNL. Amounts are sent to ONVO as integers in the smallest currency
 * unit (e.g. cents), exactly like ONVO expects.
 *
 * @copyright FOSSBilling
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Intl\Currencies;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class Payment_Adapter_Onvopay implements FOSSBilling\InjectionAwareInterface
{
    /** Base URL for every ONVO API request. */
    private const API_BASE_URL = 'https://api.onvopay.com';

    /** URL of ONVO's Web SDK script (see https://docs.onvopay.com/integrations/sdk). */
    private const SDK_SCRIPT_URL = 'https://sdk.onvopay.com/sdk.js';

    /** Currencies ONVO's API accepts (see https://docs.onvopay.com/openapi.yaml). */
    private const SUPPORTED_CURRENCIES = ['USD', 'CRC', 'GTQ', 'NIO', 'PAB', 'PEN', 'MXN', 'COP', 'HNL'];

    /** Webhook events this adapter reacts to, per https://docs.onvopay.com/webhooks. */
    private const EVENT_PAYMENT_INTENT_SUCCEEDED = 'payment-intent.succeeded';
    private const EVENT_PAYMENT_INTENT_FAILED = 'payment-intent.failed';
    private const EVENT_PAYMENT_INTENT_DEFERRED = 'payment-intent.deferred';

    protected ?Pimple\Container $di = null;

    public function __construct(private $config)
    {
        if ($this->isTestMode()) {
            if (empty($this->config['test_secret_key'])) {
                throw new Payment_Exception(
                    'The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing',
                    [':pay_gateway' => 'ONVO', ':missing' => 'Test Secret Key'],
                    4001
                );
            }
            if (empty($this->config['test_publishable_key'])) {
                throw new Payment_Exception(
                    'The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing',
                    [':pay_gateway' => 'ONVO', ':missing' => 'Test Publishable Key'],
                    4001
                );
            }
        } else {
            if (empty($this->config['secret_key'])) {
                throw new Payment_Exception(
                    'The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing',
                    [':pay_gateway' => 'ONVO', ':missing' => 'Secret Key'],
                    4001
                );
            }
            if (empty($this->config['publishable_key'])) {
                throw new Payment_Exception(
                    'The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing',
                    [':pay_gateway' => 'ONVO', ':missing' => 'Publishable Key'],
                    4001
                );
            }
        }
    }

    public function setDi(Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?Pimple\Container
    {
        return $this->di;
    }

    public static function getConfig(): array
    {
        return [
            'supports_one_time_payments' => true,
            'supports_subscriptions' => false,
            // The whole payment form now runs inside FOSSBilling's own page
            // (ONVO's Web SDK renders a card form into a <div>, it never
            // navigates the browser away), so it is safe to embed.
            'can_load_in_iframe' => true,
            'description' => 'Accept card payments (Visa/Mastercard) without leaving your site, using ONVO\'s Web SDK in a modal window. Get your keys from the ONVO Dashboard (https://onvopay.com/dashboard): the Secret Key is used on the server and the Publishable Key in the browser. For production, also register in ONVO > Developers > Webhooks the "Notify URL" that FOSSBilling shows for this gateway, subscribed to "payment-intent.succeeded" and "payment-intent.failed", and paste the generated Webhook Secret into the corresponding field.',
            'logo' => [
                'logo' => 'onvopay.png',
                'height' => '40px',
                'width' => '120px',
            ],
            'form' => [
                'publishable_key' => [
                    'text', [
                        'label' => 'Live Publishable Key (onvo_live_publishable_key_...):',
                        'required_when' => ['enabled' => true, 'test_mode' => false],
                    ],
                ],
                'secret_key' => [
                    'text', [
                        'label' => 'Live Secret Key (onvo_live_secret_key_...):',
                        'required_when' => ['enabled' => true, 'test_mode' => false],
                    ],
                ],
                'webhook_secret' => [
                    'password', [
                        'label' => 'Live Webhook Secret (webhook_secret_...):',
                        'required_when' => ['enabled' => true, 'test_mode' => false],
                    ],
                ],
                'test_publishable_key' => [
                    'text', [
                        'label' => 'Test Publishable Key (onvo_test_publishable_key_...):',
                        'required_when' => ['enabled' => true, 'test_mode' => true],
                    ],
                ],
                'test_secret_key' => [
                    'text', [
                        'label' => 'Test Secret Key (onvo_test_secret_key_...):',
                        'required_when' => ['enabled' => true, 'test_mode' => true],
                    ],
                ],
                'test_webhook_secret' => [
                    'password', [
                        'label' => 'Test Webhook Secret (webhook_secret_...):',
                        'required_when' => ['enabled' => true, 'test_mode' => true],
                    ],
                ],
            ],
        ];
    }

    /**
     * Builds the "Pay" button + modal that hosts ONVO's embedded Web SDK.
     */
    public function getHtml($api_admin, $invoice_id, $subscription): string
    {
        $invoice = $this->di['db']->load('Invoice', $invoice_id);

        $currency = strtoupper((string) $invoice->currency);
        if (!in_array($currency, self::SUPPORTED_CURRENCIES, true)) {
            throw new FOSSBilling\Exception(
                'ONVO does not support the currency ":currency". Supported currencies are: :list',
                [':currency' => $currency, ':list' => implode(', ', self::SUPPORTED_CURRENCIES)]
            );
        }

        // Best effort: attaching a Customer lets ONVO's dashboard show who
        // paid and lets the buyer save the card. Never block checkout if
        // this fails (e.g. odd characters in an optional field).
        $customerId = $this->createOnvoCustomer($invoice);

        $intentPayload = [
            'amount' => $this->getAmountInMinorUnits($invoice),
            'currency' => $currency,
            'description' => $this->getInvoiceTitle($invoice),
            'metadata' => [
                'invoice_id' => (string) $invoice->id,
                'invoice_hash' => (string) $invoice->hash,
                'client_id' => (string) $invoice->client_id,
            ],
        ];
        if ($customerId !== null) {
            $intentPayload['customerId'] = $customerId;
        }

        $intent = $this->apiRequest('POST', '/v1/payment-intents', $intentPayload);
        if (empty($intent['id'])) {
            throw new FOSSBilling\Exception('ONVO did not return a payment intent id. Please try again in a moment.');
        }

        $notifyUrl = (string) ($this->config['notify_url'] ?? '');

        $publicKeyJs = json_encode($this->getPublishableKey(), JSON_UNESCAPED_SLASHES);
        $paymentIntentIdJs = json_encode($intent['id'], JSON_UNESCAPED_SLASHES);
        $invoiceIdJs = json_encode((string) $invoice->id, JSON_UNESCAPED_SLASHES);
        $invoiceHashJs = json_encode((string) $invoice->hash, JSON_UNESCAPED_SLASHES);
        $notifyUrlJs = json_encode($notifyUrl, JSON_UNESCAPED_SLASHES);
        $customerIdLine = $customerId !== null
            ? 'customerId: ' . json_encode($customerId, JSON_UNESCAPED_SLASHES) . ",\n                        "
            : '';

        return <<<HTML
            <div class="onvo-pay-wrap">
                <button type="button" id="onvo-pay-open-btn" class="btn btn-primary">Pay with card (ONVO)</button>
            </div>

            <div id="onvo-pay-backdrop" class="onvo-pay-backdrop" style="display:none;">
                <div class="onvo-pay-modal" role="dialog" aria-modal="true" aria-label="Secure payment with ONVO">
                    <div class="onvo-pay-modal-header">
                        <span>Secure payment with ONVO</span>
                        <button type="button" id="onvo-pay-close-btn" class="onvo-pay-close" aria-label="Close">&times;</button>
                    </div>
                    <div class="onvo-pay-modal-body">
                        <div id="onvo-pay-error" class="onvo-pay-error" style="display:none;"></div>
                        <div id="onvo-pay-processing" class="onvo-pay-processing" style="display:none;">Confirming your payment…</div>
                        <div id="onvo-container"></div>
                    </div>
                </div>
            </div>

            <style>
                .onvo-pay-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 10500; display: flex; align-items: center; justify-content: center; padding: 1em; }
                .onvo-pay-modal { background: #fff; border-radius: 8px; width: 100%; max-width: 460px; max-height: 90vh; overflow-y: auto; box-shadow: 0 10px 30px rgba(0,0,0,.25); }
                .onvo-pay-modal-header { display: flex; align-items: center; justify-content: space-between; padding: .9em 1.2em; border-bottom: 1px solid #e5e5e5; font-weight: 600; }
                .onvo-pay-close { background: none; border: none; font-size: 1.4em; line-height: 1; cursor: pointer; color: #666; }
                .onvo-pay-modal-body { padding: 1.2em; }
                .onvo-pay-error { background: #fdecea; color: #611a15; border: 1px solid #f5c6cb; border-radius: 6px; padding: .7em 1em; margin-bottom: 1em; font-size: .95em; }
                .onvo-pay-processing { text-align: center; padding: 1em 0; font-size: .95em; color: #333; }
            </style>

            <script src="{$this->sdkScriptUrl()}"></script>
            <script>
            (function () {
                var openBtn = document.getElementById('onvo-pay-open-btn');
                var closeBtn = document.getElementById('onvo-pay-close-btn');
                var backdrop = document.getElementById('onvo-pay-backdrop');
                var errorBox = document.getElementById('onvo-pay-error');
                var processingBox = document.getElementById('onvo-pay-processing');
                var rendered = false;

                var PUBLIC_KEY = {$publicKeyJs};
                var PAYMENT_INTENT_ID = {$paymentIntentIdJs};
                var INVOICE_ID = {$invoiceIdJs};
                var INVOICE_HASH = {$invoiceHashJs};
                var NOTIFY_URL = {$notifyUrlJs};

                var CARD_ERROR_MESSAGES = {
                    issuer_declined: 'Your bank declined the card. Try another card or contact your bank.',
                    gateway_declined: 'The card could not be accepted. Check the details and try again.',
                    onvo_declined: 'The payment could not be processed due to a security rule. Try another card.',
                    processor_error: 'A technical error occurred while processing the payment. Try again in a few minutes.',
                    unknown: 'The card could not be verified. Check the details and try again.'
                };

                function showError(message) {
                    errorBox.textContent = message;
                    errorBox.style.display = 'block';
                }

                function renderOnvo() {
                    onvo.pay({
                        publicKey: PUBLIC_KEY,
                        paymentIntentId: PAYMENT_INTENT_ID,
                        paymentType: 'one_time',
                        {$customerIdLine}locale: 'en',
                        onError: function (data) {
                            processingBox.style.display = 'none';
                            var message = (data && data.message) || 'The payment could not be processed. Try again.';
                            try {
                                var card = data && data.details && data.details.card;
                                if (card && card.reason && CARD_ERROR_MESSAGES[card.reason]) {
                                    message = CARD_ERROR_MESSAGES[card.reason];
                                }
                            } catch (e) {}
                            showError(message);
                        },
                        onSuccess: function () {
                            errorBox.style.display = 'none';
                            processingBox.style.display = 'block';

                            var params = 'invoice_id=' + encodeURIComponent(INVOICE_ID) +
                                '&invoice_hash=' + encodeURIComponent(INVOICE_HASH) +
                                '&payment_intent_id=' + encodeURIComponent(PAYMENT_INTENT_ID);
                            var url = NOTIFY_URL + (NOTIFY_URL.indexOf('?') > -1 ? '&' : '?') + params;

                            fetch(url, { method: 'GET', credentials: 'omit' })['catch'](function () {})
                                .then(function () {
                                    window.location.reload();
                                });

                            // Safety net in case fetch() hangs indefinitely.
                            setTimeout(function () { window.location.reload(); }, 8000);
                        }
                    }).render('#onvo-container');
                }

                openBtn.addEventListener('click', function () {
                    backdrop.style.display = 'flex';
                    if (!rendered) {
                        renderOnvo();
                        rendered = true;
                    }
                });
                closeBtn.addEventListener('click', function () {
                    backdrop.style.display = 'none';
                });
                backdrop.addEventListener('click', function (e) {
                    if (e.target === backdrop) {
                        backdrop.style.display = 'none';
                    }
                });
            })();
            </script>
            HTML;
    }

    /**
     * Handles both legs that can hit this gateway's IPN endpoint:
     *  - our own page's `onSuccess` ping (GET, same-origin, unauthenticated)
     *  - ONVO's authenticated server-to-server webhook (POST, JSON body)
     *
     * Neither leg is trusted at face value: both end up in
     * settlePaymentIntent(), which always re-fetches the Payment Intent
     * from ONVO before crediting anything. This means an unauthenticated or
     * even forged ping to this endpoint cannot cause funds to be credited
     * unless ONVO's own API independently confirms a succeeded payment that
     * matches the invoice amount — and doing so twice is a safe no-op.
     */
    public function processTransaction($api_admin, $id, $data, $gateway_id): void
    {
        $tx = $this->di['db']->getExistingModelById('Transaction', $id);

        $rawBody = $data['http_raw_post_data'] ?? '';
        $webhook = null;
        if (is_string($rawBody) && $rawBody !== '') {
            $decoded = json_decode($rawBody, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) && isset($decoded['type'])) {
                $webhook = $decoded;
            }
        }

        if ($webhook !== null) {
            $this->verifyWebhookSignature();
            $this->handleWebhookEvent($tx, $webhook);

            return;
        }

        // Note: invoice_id/invoice_hash also travel in this URL (see getHtml())
        // purely for readability in server access logs. They are intentionally
        // never used to decide which invoice to credit — settlePaymentIntent()
        // only trusts the invoice_id ONVO itself echoes back in the verified
        // Payment Intent's metadata, so a tampered query string cannot redirect
        // a payment's credit to a different invoice.
        $paymentIntentId = $data['get']['payment_intent_id'] ?? $data['post']['payment_intent_id'] ?? null;
        if ($paymentIntentId) {
            $this->settlePaymentIntent($tx, (string) $paymentIntentId);

            return;
        }

        if (empty($tx->status)) {
            $tx->status = Model_Transaction::STATUS_RECEIVED;
        }
        $tx->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($tx);
    }

    /**
     * Routes a verified webhook payload to the right handler.
     */
    private function handleWebhookEvent(Model_Transaction $tx, array $webhook): void
    {
        $eventType = (string) $webhook['type'];
        $eventData = is_array($webhook['data'] ?? null) ? $webhook['data'] : [];

        $tx->txn_status = $eventType;

        switch ($eventType) {
            case self::EVENT_PAYMENT_INTENT_SUCCEEDED:
                $piId = $eventData['id'] ?? null;
                if ($piId) {
                    $this->settlePaymentIntent($tx, (string) $piId);
                } else {
                    $this->recordInformationalEvent($tx, $eventData, Model_Transaction::STATUS_ERROR, 'ONVO webhook was missing a payment intent id.');
                }
                break;

            case self::EVENT_PAYMENT_INTENT_FAILED:
                $this->recordInformationalEvent($tx, $eventData, Model_Transaction::STATUS_ERROR, $eventData['error']['message'] ?? 'ONVO reported a failed payment intent.');
                break;

            case self::EVENT_PAYMENT_INTENT_DEFERRED:
            default:
                // Other event types (deferred confirmations, subscription
                // renewals, mobile-transfer notifications, etc.) are only
                // logged on the transaction. We still acknowledge with 2xx
                // so ONVO does not keep retrying.
                $this->recordInformationalEvent($tx, $eventData, Model_Transaction::STATUS_RECEIVED, null);
                break;
        }
    }

    /**
     * The single place allowed to add funds and settle an invoice. Always
     * re-verifies the Payment Intent against ONVO's API before doing so,
     * and is idempotent/safe to call multiple times for the same intent.
     */
    private function settlePaymentIntent(Model_Transaction $tx, string $paymentIntentId): void
    {
        $intent = $this->apiRequest('GET', '/v1/payment-intents/' . rawurlencode($paymentIntentId));
        $tx->txn_id = $paymentIntentId;

        // Strictly use the invoice_id ONVO echoes back on the verified
        // Payment Intent — never a caller-supplied value — so this can never
        // be tricked into crediting the wrong invoice.
        $invoice = $this->resolveInvoiceFromMetadata((array) ($intent['metadata'] ?? []));
        if (!$invoice) {
            $tx->status = Model_Transaction::STATUS_ERROR;
            $tx->error = 'Could not resolve a FOSSBilling invoice from ONVO payment intent ' . $paymentIntentId;
            $tx->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($tx);

            return;
        }
        $tx->invoice_id = $invoice->id;

        // Idempotency: this can be reached from both the browser ping and
        // the webhook (in either order, and possibly more than once).
        if ((string) $invoice->status === 'paid') {
            $tx->status = Model_Transaction::STATUS_PROCESSED;
            $tx->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($tx);

            return;
        }

        $transactionService = $this->di['mod_service']('Invoice', 'Transaction');
        if (!$transactionService->claimForProcessing((int) $tx->id)) {
            return;
        }
        $tx->status = Model_Transaction::STATUS_PROCESSING;

        $intentStatus = $intent['status'] ?? null;
        if ($intentStatus !== 'succeeded') {
            $tx->status = in_array($intentStatus, ['failed', 'canceled'], true)
                ? Model_Transaction::STATUS_ERROR
                : Model_Transaction::STATUS_RECEIVED;
            $tx->error = 'ONVO payment intent status: ' . ($intentStatus ?? 'unknown');
            $tx->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($tx);

            return;
        }

        $currency = strtoupper((string) ($intent['currency'] ?? $invoice->currency));
        $amount = $this->getAmountFromMinorUnits((int) ($intent['amount'] ?? 0), $currency);

        $invoiceService = $this->di['mod_service']('Invoice');
        $expected = $invoiceService->getTotalWithTax($invoice);
        try {
            $invoiceService->validatePaymentAmount($amount, $expected);
        } catch (FOSSBilling\Exception $e) {
            $tx->status = Model_Transaction::STATUS_ERROR;
            $tx->error = $e->getMessage();
            $tx->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($tx);
            throw $e;
        }

        $tx->amount = $amount;
        $tx->currency = $currency;

        $clientService = $this->di['mod_service']('Client');
        $client = $this->di['db']->getExistingModelById('Client', $invoice->client_id);
        $clientService->addFunds($client, $amount, 'ONVO payment ' . $paymentIntentId, [
            'amount' => $amount,
            'description' => 'ONVO payment ' . $paymentIntentId,
            'type' => 'transaction',
            'rel_id' => $tx->id,
        ]);

        if (!$invoiceService->isInvoiceTypeDeposit($invoice)) {
            if (!$invoice->approved) {
                $invoiceService->approveInvoice($invoice, ['use_credits' => false]);
            }
            $invoiceService->payInvoiceWithCredits($invoice);
        }

        $tx->status = Model_Transaction::STATUS_PROCESSED;
        $tx->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($tx);
    }

    /**
     * Stores non-crediting webhook events on the transaction row for
     * visibility/debugging without touching invoices or client balances.
     */
    private function recordInformationalEvent(Model_Transaction $tx, array $eventData, string $status, ?string $error): void
    {
        $invoice = $this->resolveInvoiceFromMetadata((array) ($eventData['metadata'] ?? []));
        if ($invoice) {
            $tx->invoice_id = $invoice->id;
        }
        if (isset($eventData['id'])) {
            $tx->txn_id = $eventData['id'];
        }
        $tx->status = $status;
        if ($error !== null) {
            $tx->error = $error;
        }
        $tx->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($tx);
    }

    /**
     * Resolves a FOSSBilling invoice strictly from the `metadata.invoice_id`
     * we set when the Payment Intent was created. Deliberately has no
     * fallback to a caller-supplied invoice id: this is the only thing that
     * decides which invoice gets credited, so it must come from data ONVO
     * itself returned for an intent we already independently verified.
     */
    private function resolveInvoiceFromMetadata(array $metadata): ?Model_Invoice
    {
        $invoiceId = $metadata['invoice_id'] ?? null;
        if (!$invoiceId) {
            return null;
        }

        try {
            return $this->di['db']->getExistingModelById('Invoice', (int) $invoiceId);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Creates a best-effort ONVO Customer for the invoice's buyer so the
     * SDK/dashboard can associate the payment with a real customer profile.
     * Never blocks checkout: returns null on any failure.
     */
    private function createOnvoCustomer(Model_Invoice $invoice): ?string
    {
        $payload = [];

        $name = trim((string) ($invoice->buyer_first_name . ' ' . $invoice->buyer_last_name));
        if ($name !== '') {
            $payload['name'] = $name;
        }
        if (!empty($invoice->buyer_email)) {
            $payload['email'] = $invoice->buyer_email;
        }
        if (!empty($invoice->buyer_phone)) {
            $payload['phone'] = $invoice->buyer_phone;
        }

        if (empty($payload)) {
            return null;
        }

        try {
            $customer = $this->apiRequest('POST', '/v1/customers', $payload);

            return $customer['id'] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Confirms the `X-Webhook-Secret` header ONVO sends with every webhook
     * request against the secret configured for this gateway/mode.
     *
     * @see https://docs.onvopay.com/webhooks#seguridad
     */
    private function verifyWebhookSignature(): void
    {
        $expected = $this->getWebhookSecret();
        if (empty($expected)) {
            throw new FOSSBilling\Exception('An ONVO webhook was received, but no Webhook Secret is configured for this gateway. Configure it before enabling the ONVO webhook in production.');
        }

        $received = $_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? '';
        if ($received === '' || !hash_equals($expected, $received)) {
            throw new FOSSBilling\Exception('Rejected an ONVO webhook request: the X-Webhook-Secret header did not match the configured Webhook Secret.');
        }
    }

    private function isTestMode(): bool
    {
        return (bool) ($this->config['test_mode'] ?? false);
    }

    private function getSecretKey(): string
    {
        return (string) ($this->isTestMode() ? $this->config['test_secret_key'] : $this->config['secret_key']);
    }

    private function getPublishableKey(): string
    {
        return (string) ($this->isTestMode() ? $this->config['test_publishable_key'] : $this->config['publishable_key']);
    }

    private function getWebhookSecret(): ?string
    {
        $key = $this->isTestMode() ? 'test_webhook_secret' : 'webhook_secret';

        return $this->config[$key] ?? null;
    }

    private function sdkScriptUrl(): string
    {
        return self::SDK_SCRIPT_URL;
    }

    private function httpClient(): HttpClientInterface
    {
        return HttpClient::create();
    }

    /**
     * Calls the ONVO REST API with the configured Secret Key and decodes the
     * JSON response, translating non-2xx responses into FOSSBilling\Exception
     * using ONVO's documented error shape.
     *
     * @see https://docs.onvopay.com/reference/errors
     */
    private function apiRequest(string $method, string $path, ?array $body = null): array
    {
        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->getSecretKey(),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'timeout' => 30,
        ];
        if ($body !== null) {
            $options['json'] = $body;
        }

        try {
            $response = $this->httpClient()->request($method, self::API_BASE_URL . $path, $options);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (\Throwable $e) {
            throw new FOSSBilling\Exception('Could not reach the ONVO API: :msg', [':msg' => $e->getMessage()]);
        }

        $decoded = json_decode($content, true);
        $data = is_array($decoded) ? $decoded : [];

        if ($status >= 400) {
            $message = $data['message'] ?? $data['error'] ?? ('HTTP ' . $status);
            if (is_array($message)) {
                $message = implode('; ', $message);
            }
            throw new FOSSBilling\Exception('ONVO API error (:status): :msg', [':status' => $status, ':msg' => (string) $message]);
        }

        return $data;
    }

    private function getCurrencyFractionDigits(string $currency): int
    {
        $currency = strtoupper($currency);

        return Currencies::exists($currency) ? Currencies::getFractionDigits($currency) : 2;
    }

    private function getAmountInMinorUnits(Model_Invoice $invoice): int
    {
        $invoiceService = $this->di['mod_service']('Invoice');
        $amount = $invoiceService->getTotalWithTax($invoice);
        $multiplier = 10 ** $this->getCurrencyFractionDigits((string) $invoice->currency);

        return (int) round($amount * $multiplier);
    }

    private function getAmountFromMinorUnits(int $amount, string $currency): float
    {
        $divisor = 10 ** $this->getCurrencyFractionDigits($currency);

        return $amount / $divisor;
    }

    private function getInvoiceTitle(Model_Invoice $invoice): string
    {
        $invoiceItems = $this->di['db']->getAll('SELECT title FROM invoice_item WHERE invoice_id = :invoice_id', [':invoice_id' => $invoice->id]);

        $params = [
            ':id' => sprintf('%05s', $invoice->nr),
            ':serie' => $invoice->serie,
            ':title' => $invoiceItems[0]['title'] ?? '',
        ];

        $title = __trans('Payment for invoice :serie:id [:title]', $params);
        if (FOSSBilling\Tools::safeCount($invoiceItems) > 1) {
            $title = __trans('Payment for invoice :serie:id', $params);
        }

        return $title;
    }
}
