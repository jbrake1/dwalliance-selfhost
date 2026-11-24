# Affiliate program terms

Refs #79. Each brand's affiliate join page shows terms text. A brand owner can write their own, or
use the default wording that `DwAffiliate::termsForSettings()` generates from that brand's discount
settings and display name.

## Operator placeholders

For the platform's own program (the brand on `PLATFORM_DOMAIN`), the default wording can end with a
short legal clause built from these DwConfig keys. Each sentence appears only when its key is set:

| Key | Sentence |
|---|---|
| `AFFILIATE_TERMS_COMPANY` (+ `AFFILIATE_TERMS_SITE`) | "This program is run by COMPANY (SITE)." |
| `AFFILIATE_TERMS_CONTACT` | "Questions about these terms: CONTACT." |
| `AFFILIATE_TERMS_JURISDICTION` | "These terms are governed by the laws of JURISDICTION." |

`AFFILIATE_TERMS_NOTICE`, when set, is shown to brand owners under the terms box in `/affiliate/`.
The self-host release uses it to remind operators about legal review.

The hosted service leaves all of these empty, so its terms are unchanged.

## Legal review is required

**The default terms are a plain-language starting point, not legal advice, and they have not been
written for any particular business or jurisdiction.** Anyone who runs an affiliate program with
this software, including self-host operators and brand owners, must have their own lawyer review and
adapt the terms before using them. The authors make no warranty that the terms are suitable or
enforceable.
