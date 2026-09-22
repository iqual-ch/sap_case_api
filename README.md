# iqual SAP Case API

Submits Drupal webform submissions to the SAP Service Cloud *Formular Case
Request API* on SAP CPI, which creates a case including the customer record.

The API serves several contact form variants so one site may use the handler on
several webforms, and several sites may share one SAP tenant. The connection is
therefore configured once per site, the field mapping once per webform.

## Installation

```bash
composer require iqual/sap_case_api
drush en sap_case_api
```

## How it fits together

| Piece | Responsibility |
|---|---|
| `sap_case_api` webform handler | Maps one form's elements onto the API schema and triggers delivery. Added per webform under *Settings → Emails/Handlers*. |
| `sap_case_api.settings` | Endpoint URL, token URL, timeout, and which OAuth2 client to use. Holds no credentials. |
| `oauth2_client` | Performs the OAuth2 client credentials grant and stores the token in state. |
| `key` | Reads the client ID and secret from an environment variable. |
| `sap_case_api_retry` queue | Holds deliveries that failed for a transient reason; retried on cron. |

## Setup

### 1. Credentials

The client ID and secret travel together, **base64 encoded**, in one
environment variable. The decoded value is the JSON the *Oauth2 Client* key
type expects:

```json
{"client_id": "<client id>", "client_secret": "<client secret>"}
```

Encode it with — the `tr` is required on macOS, whose `base64` wraps at 76
characters:

```bash
printf '%s' '{"client_id":"…","client_secret":"…"}' | base64 | tr -d '\n'
```

> **Why base64 and not plain JSON?** DDEV writes each `web_environment` entry
> into its generated docker-compose file wrapped in double quotes, so a value
> containing `"` terminates that string early and `ddev start` fails. Base64
> produces only `A–Z a–z 0–9 + / =`, which survives.

Then create a key (*Configuration → System → Keys*) of type **Oauth2 Client**
with the **Environment** provider, the variable's name, and *Base64-encoded*
checked.

> Do not use the Key module's *Configuration* provider: it writes the value
> into the `key.key.*` configuration entity, which is exported and committed.

Finally create an OAuth2 client (*Configuration → System → OAuth2 Client*)
using the `sap_case_api` plugin, credential provider **Key**, pointing at
that key — and enable it.

### 2. Endpoints

At */admin/config/services/sap-case-api*. These differ per environment, so set
them per environment rather than exporting them:

```php
$config['sap_case_api.settings']['endpoint_url'] = 'https://<host>/http/SCV2-FormularIntegration/Inbound';
$config['sap_case_api.settings']['token_uri'] = 'https://<subdomain>.authentication.eu10.hana.ondemand.com/oauth/token';
```

### 3. The handler

Add *SAP Service Cloud case* to the webform, choose the contact form variant,
set a subject, and adjust the mapping to the form's own element keys. The
handler ships with an example mapping for a German language contact form;
saving validates every element key against the form and names any that do not
exist.

## Field mapping

One line per field:

```
api.property: element_key
api.property: element_key|transform
api.property: element_key|transform:argument
```

| Transform | Effect |
|---|---|
| *(none)* | The value as a string. |
| `date` | Reformats to `Y-m-d`, as `incidentDate` requires. |
| `time` | Reformats to `H:i:s`, as `incidentTime` requires. |
| `form_of_address` | Maps a salutation to the SAP codes: Frau → `0001`, Herr → `0002`, anything else → `Z001`. |
| `boolean:<value>` | Emits a real JSON boolean — `true` when the submitted value equals `<value>`. |

Lines starting with `#` are ignored. Empty values are omitted rather than sent
as empty strings, and strings are truncated to the maximum lengths the API
schema defines. `requestId`, `contactFormVariant`, `reportedOn` and
`case.subject` are set by the handler, not by the mapping.

Attachments are optional: name the file upload elements in *Attachment
elements*. At most 5 files are sent, and only in the MIME types the API
accepts — anything else is skipped with a warning.

## Delivery, retries and failures

`requestId` is the webform submission UUID and stays identical across retries,
so a redelivery can be recognised by SAP as a duplicate rather than opening a
second case.

A failure is either **permanent** — a rejected payload (HTTP 4xx), a
`status: error` response, or a mapping that produced no value for a required
property — or **transient**: network errors, HTTP 408, 429 and 5xx, and a
missing access token. Permanent failures are logged and dropped. Transient
failures are queued and retried on cron after 5 minutes, 15 minutes, 1 hour,
3 hours and 12 hours, up to the handler's *Maximum delivery attempts*, after
which the failure is logged as **critical**.

A failing API never affects the visitor: the submission is saved and any email
handlers still run, provided this handler is weighted after them.

```bash
drush ws --type=sap_case_api        # Delivery log
drush queue:list                       # Pending retries
drush queue:run sap_case_api_retry  # Process retries now
```

## Tests

Run from a site that has the module installed:

```bash
vendor/bin/phpunit --testsuite unit   --filter=CaseApi
vendor/bin/phpunit --testsuite kernel --filter=CaseApi
```
