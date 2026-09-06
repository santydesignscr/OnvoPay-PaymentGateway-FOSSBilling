# ONVO (OnvoPay) for FOSSBilling — embedded modal payment (Web SDK)

Payment gateway adapter that integrates ONVO's **Web SDK** directly into
the invoice page, inside a modal window. **No redirect and no payment
link**: the card form is rendered by ONVO's SDK inside your own site, and
the payment is confirmed without ever leaving the app.

Built strictly from ONVO's official documentation:
- Authentication: https://docs.onvopay.com/authentication
- Payment intents: https://docs.onvopay.com/payments/payment-intents
- Web SDK: https://docs.onvopay.com/integrations/sdk
- Webhooks: https://docs.onvopay.com/webhooks
- Errors: https://docs.onvopay.com/reference/errors

## How it works

1. The client opens the invoice and clicks **"Pay with card
   (ONVO)"** → a modal opens.
2. `getHtml()` has already created, server-side, a **Customer** (best
   effort) and a **Payment Intent** (`POST /v1/payment-intents`) for the
   invoice's exact total.
3. Opening the modal loads `https://sdk.onvopay.com/sdk.js` and calls
   `onvo.pay({ publicKey, paymentIntentId, paymentType: "one_time", ... })
   .render("#onvo-container")`, which draws ONVO's card form **inside the
   modal**. The SDK handles tokenizing the card, creating the payment
   method, and confirming the intent (including 3-D Secure if required)
   without reloading or redirecting the page.
4. When the SDK fires `onSuccess`, the browser makes a `fetch()` call to
   this gateway's endpoint with the `paymentIntentId`, so FOSSBilling can
   mark the invoice as paid **instantly**, then reloads the page.
5. In parallel — and independently — ONVO also sends
   `payment-intent.succeeded` / `payment-intent.failed` webhooks
   authenticated with the `X-Webhook-Secret` header. This is the
   **authoritative** path: it covers cases where step 4 never reaches the
   server (closed tab, dropped connection, a 3DS flow that continues in the
   background, etc.).

**Both paths converge on the same `settlePaymentIntent()` function**, which:
- Never trusts what arrives from the browser or the webhook: it always
  re-fetches `GET /v1/payment-intents/{id}` from ONVO's API with your
  Secret Key before crediting anything.
- Resolves the invoice **only** from the `metadata.invoice_id` that ONVO
  itself returns on that verified intent (never from an `invoice_id` that
  shows up in the URL), so it cannot be manipulated into crediting the
  wrong invoice.
- Is idempotent: if the invoice is already paid, it does not credit again.

## Installation

1. Copy `Onvopay.php` to `library/Payment/Adapter/Onvopay.php` in your
   FOSSBilling installation.
2. (Optional) Place a logo at `library/Payment/Adapter/onvopay.png`.
3. In the admin panel: **Configuration → Payment gateways → New payment
   gateway**, search for "Onvopay" and install it.

## Configuration in FOSSBilling

| Field | Where to get it |
| --- | --- |
| **Live Publishable Key** | ONVO Dashboard → API Keys → `onvo_live_publishable_key_...` (used in the browser) |
| **Live Secret Key** | ONVO Dashboard → API Keys → `onvo_live_secret_key_...` (server only) |
| **Live Webhook Secret** | ONVO Dashboard → Developers → Webhooks → `webhook_secret_...` |
| **Test Publishable Key / Test Secret Key / Test Webhook Secret** | Same as above, but in test mode |

Turn on **"Test mode"** while testing with `onvo_test_` keys. The
Publishable Key is never secret: it travels to the browser inside the HTML
`getHtml()` generates, exactly as the SDK documentation describes. The
Secret Key never leaves the server.

## Setting up the webhook in ONVO (recommended for production)

Even though the modal already confirms the payment instantly via
`fetch()`, the webhook is the safety net for cases where that call never
reaches your server (closed tab, unstable network, etc.):

1. Save the gateway once in FOSSBilling and open it again: FOSSBilling will
   show the **Notify URL / Callback URL** for this gateway (something like
   `https://your-domain.com/ipn.php?gateway_id=N`).
2. In the ONVO Dashboard, go to **Developers → Webhooks** and create an
   endpoint with that URL, subscribed to **`payment-intent.succeeded`** and
   **`payment-intent.failed`**.
3. Copy the generated `webhook_secret_...` and paste it into "Webhook
   Secret" (or "Test Webhook Secret").
4. Repeat for test mode and live mode (ONVO treats them as independent
   webhooks/keys).

## Supported currencies

`USD`, `CRC`, `GTQ`, `NIO`, `PAB`, `PEN`, `MXN`, `COP`, `HNL`. If an
invoice uses a different currency, the adapter throws a clear error
instead of trying to charge in an unsupported currency.

## Security built in

- The **Secret Key** is never exposed to the browser; only the Publishable
  Key (designed for public use) travels to the client, exactly as ONVO
  specifies.
- The ping sent by the browser itself (`onSuccess` → `fetch()`) does
  **not** need to be authenticated to be safe: which invoice gets credited
  is always decided from a live, authenticated call to ONVO's API, never
  from the contents of that ping. Replaying it, or even forging it, cannot
  credit funds that ONVO does not independently confirm.
- Every real ONVO webhook is authenticated by comparing the
  `X-Webhook-Secret` header against the configured secret (`hash_equals`,
  timing-attack safe).
- The amount received is validated against the invoice's real total
  (`validatePaymentAmount`) before it is marked as paid.
- `claimForProcessing` is used to avoid crediting the same payment twice if
  the browser ping and the webhook arrive almost simultaneously, or if
  ONVO retries the webhook delivery.

## Testing (test mode)

With `onvo_test_` keys and the modal open, use these cards (any future
expiry date and a valid CVV):

| Scenario | Brand | Number |
| --- | --- | --- |
| Approved | Visa | `4242 4242 4242 4242` |
| Approved | Mastercard | `5555 5555 5555 4444` |
| 3DS challenge | Visa | `4000 0000 0000 3220` |
| Declined payment | Visa | `4000 0000 0000 0002` |

Checklist before going to production (per ONVO):
- [ ] Successful payment: the modal shows success and the invoice is paid
      without a manual reload.
- [ ] Declined card: the modal shows the error message and allows a retry.
- [ ] Disconnect the network right after paying (or close the tab) and
      confirm the webhook still marks the invoice as paid.
- [ ] Manually resend the same webhook (retry) and confirm it does
      **not** duplicate the credit to the client.
- [ ] Switch to `onvo_live_` keys and repeat with a small real charge.

## Notes

- The adapter exclusively uses **Payment Intents + Web SDK**
  (`paymentType: "one_time"`), so `supports_subscriptions` is set to
  `false`. ONVO's recurring charges are not integrated here.
- `can_load_in_iframe` is `true`: unlike a hosted payment page, the SDK
  draws the form inside your own page, so it's safe for FOSSBilling to
  show it embedded.
- If you later add Apple Pay/Google Pay or saved cards, those flows also
  go through Payment Intents, so `settlePaymentIntent()` won't need any
  changes.
