# Laravel Cloud Trigger

Questa guida documenta come far partire il Cloud Run Job da un'altra app Laravel che gira su Laravel Cloud.

Dato che Laravel Cloud non gira dentro GCP, serve un service account Google dedicato e una chiave JSON da conservare nei secret dell'app.

## 1. Variabili di lavoro

```bash
PROJECT_ID="YOUR_PROJECT_ID"
REGION="YOUR_REGION"
JOB_NAME="YOUR_JOB_NAME"
CALLER_SERVICE_ACCOUNT_NAME="laravel-cloud-run-job-caller"
CALLER_SERVICE_ACCOUNT_EMAIL="${CALLER_SERVICE_ACCOUNT_NAME}@${PROJECT_ID}.iam.gserviceaccount.com"
KEY_DIR="${HOME}/.gcp"
KEY_PATH="${KEY_DIR}/${CALLER_SERVICE_ACCOUNT_NAME}.json"
```

## 2. Creazione service account

```bash
gcloud iam service-accounts create "${CALLER_SERVICE_ACCOUNT_NAME}" \
  --project "${PROJECT_ID}" \
  --display-name "Laravel Cloud Run Job Caller"
```

## 3. Permesso per eseguire il job

```bash
gcloud run jobs add-iam-policy-binding "${JOB_NAME}" \
  --project "${PROJECT_ID}" \
  --region "${REGION}" \
  --member "serviceAccount:${CALLER_SERVICE_ACCOUNT_EMAIL}" \
  --role "roles/run.jobsExecutor"
```

Se l'app deve eseguire il job con overrides runtime, usa `roles/run.jobsExecutorWithOverrides`.

## 4. Generazione chiave JSON

```bash
mkdir -p "${KEY_DIR}"

gcloud iam service-accounts keys create "${KEY_PATH}" \
  --project "${PROJECT_ID}" \
  --iam-account "${CALLER_SERVICE_ACCOUNT_EMAIL}"
```

## 5. Conversione in base64 per Laravel Cloud

```bash
GCP_SERVICE_ACCOUNT_JSON_BASE64="$(base64 < "${KEY_PATH}" | tr -d '\n')"

printf 'GCP_PROJECT_ID=%s\n' "${PROJECT_ID}"
printf 'GCP_REGION=%s\n' "${REGION}"
printf 'CLOUD_RUN_JOB=%s\n' "${JOB_NAME}"
printf 'GCP_SERVICE_ACCOUNT_JSON_BASE64=%s\n' "${GCP_SERVICE_ACCOUNT_JSON_BASE64}"
```

## 6. Secret da impostare nell'altra app Laravel

Questi valori vanno nel sistema di env/secret di Laravel Cloud, non nel repository.

```dotenv
GCP_PROJECT_ID=YOUR_PROJECT_ID
GCP_REGION=YOUR_REGION
CLOUD_RUN_JOB=YOUR_JOB_NAME
GCP_SERVICE_ACCOUNT_JSON_BASE64=YOUR_BASE64_ENCODED_SERVICE_ACCOUNT_JSON
```

## 7. Integrazione applicativa

L'altra app deve:

- leggere `GCP_PROJECT_ID`, `GCP_REGION`, `CLOUD_RUN_JOB`
- leggere `GCP_SERVICE_ACCOUNT_JSON_BASE64`
- decodificare il JSON del service account in memoria
- ottenere un access token OAuth2 con scope `https://www.googleapis.com/auth/cloud-platform`
- chiamare:

```text
POST https://run.googleapis.com/v2/projects/{project}/locations/{region}/jobs/{job}:run
```

## 8. Rotazione della key

La chiave JSON e` una credenziale sensibile a lunga durata. Quando non serve piu`:

```bash
gcloud iam service-accounts keys list \
  --project "${PROJECT_ID}" \
  --iam-account "${CALLER_SERVICE_ACCOUNT_EMAIL}"
```

Per revocare una chiave:

```bash
gcloud iam service-accounts keys delete KEY_ID \
  --project "${PROJECT_ID}" \
  --iam-account "${CALLER_SERVICE_ACCOUNT_EMAIL}"
```

## 9. Pulizia file locale

Dopo aver copiato il contenuto base64 nei secret dell'altra app:

```bash
rm -f "${KEY_PATH}"
```
