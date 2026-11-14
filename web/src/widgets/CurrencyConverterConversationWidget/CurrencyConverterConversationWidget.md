---
name: CurrencyConverter
emitter: agent
summary: Currency converter — an amount in one currency, its value in another, the exchange rate and the date the rate is of; the owner edits the amount and swaps the currencies, recomputed from the delivered rate. DATA-COMPLETE.
description: Renders a currency conversion from the rate `getCurrencyExchangeRate` returned — the amount in the first currency (an editable field), its value in the second, "1 EUR = 1.12023 USD" and a chip "Rate of <day>". The owner may type another amount and swap the two currencies; both are recomputed in the browser from the delivered rate, nothing is fetched or written. Our rates are dated (one per day, cached up to a month), so the widget never says "live".
---

# CurrencyConverterConversationWidget

## Description
A currency conversion (after ChatGPT's `CurrencyConverterWidgetWithSource`). The title "Currency converter" sits above one elevated card with two rows: the first currency
(round flag badge, ISO code, the currency's name in the UI language) with the amount in an editable soft field and its
symbol on the right, a round swap button on the hairline between the rows, the second currency with the converted value
big and bold on the right. A footer line carries "1 EUR = 1.12023 USD" and "Rate of <day>". The two currencies are the
delivered pair only — there is no currency picker; swap turns the pair round and inverts the rate. The amount is
typed and shown grouped in the owner's language (`1,250,000` / `1.250.000`). In the e-mail capture the amount is plain
text and the swap button is gone.

## Data structure — DATA-COMPLETE (no re-fetch)
The tool `getCurrencyExchangeRate` (`CurrencyConversionMCPPackage`) dictates the filled block in its result; the model
copies it. The parser accepts the aliases `from`/`from_currency`/`baseCurrency`, `to`/`to_currency`/`targetCurrency`,
`exchangeRate`, `rate_date`/`date`; a block without both codes and a positive rate renders nothing and reports `failed`.
```ts
interface CurrencyConverterPayload {
  fromCurrency: string; // ISO 4217 (EUR)
  toCurrency: string;   // ISO 4217 (USD)
  amount?: number;      // in fromCurrency, default 1
  rate: number;         // 1 fromCurrency = rate toCurrency
  rateDate?: string;    // Y-m-d, the day the rate is of
}
```
Concrete payload example:
```json
{ "fromCurrency": "EUR", "toCurrency": "USD", "amount": 100, "rate": 1.12023, "rateDate": "2026-10-25" }
```

## When to use
- After `getCurrencyExchangeRate` on a screen, whenever the answer is a conversion between two currencies (a price, an invoice, a budget in another currency). Copy the block the tool result dictates — the rate and its date exactly as returned, never a rate from memory. Write a SHORT one-line intro with the converted amount FIRST, then the widget on its own line. Not for a rate history over time, not on a phone call.

## ASCII layout
```
Currency converter
┌──────────────────────────────────────────────────────────┐
│ (🇪🇺) EUR                              [ 100 ] €         │
│      Euro                                                │
│ ───────────────────── (⇅) ─────────────────────────────  │
│ (🇺🇸) USD                                  112,02 $       │
│      US-Dollar                                           │
│ 1 EUR = 1,12023 USD  ·  Rate of 25.10.2026               │
└──────────────────────────────────────────────────────────┘
```
