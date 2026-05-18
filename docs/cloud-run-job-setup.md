# Cloud Run Job Setup

Questa guida documenta come creare il job Cloud Run che esegue il comando Laravel batch.

Comando eseguito dal container:

```bash
php artisan convert-docx-to-pdf --no-interaction
```

## 1. Variabili di lavoro

```bash
PROJECT_ID="YOUR_PROJECT_ID"
REGION="YOUR_REGION"
REPOSITORY="YOUR_ARTIFACT_REGISTRY_REPOSITORY"
IMAGE_NAME="YOUR_IMAGE_NAME"
JOB_NAME="YOUR_JOB_NAME"
RUNTIME_SERVICE_ACCOUNT_NAME="YOUR_RUNTIME_SERVICE_ACCOUNT_NAME"
RUNTIME_SERVICE_ACCOUNT_EMAIL="${RUNTIME_SERVICE_ACCOUNT_NAME}@${PROJECT_ID}.iam.gserviceaccount.com"
IMAGE_URI="${REGION}-docker.pkg.dev/${PROJECT_ID}/${REPOSITORY}/${IMAGE_NAME}:latest"
```

## 2. API richieste

```bash
gcloud services enable \
  run.googleapis.com \
  artifactregistry.googleapis.com \
  cloudbuild.googleapis.com \
  secretmanager.googleapis.com \
  iamcredentials.googleapis.com \
  --project "${PROJECT_ID}"
```

## 3. Repository Docker su Artifact Registry

```bash
gcloud artifacts repositories create "${REPOSITORY}" \
  --project "${PROJECT_ID}" \
  --location "${REGION}" \
  --repository-format docker
```

Se esiste gia`, il comando puo` fallire con `ALREADY_EXISTS`. In quel caso non serve fare altro.

## 4. Service account runtime del job

```bash
gcloud iam service-accounts create "${RUNTIME_SERVICE_ACCOUNT_NAME}" \
  --project "${PROJECT_ID}" \
  --display-name "Cloud Run Job Runtime"
```

## 5. Secret runtime

Metti i valori veri in Google Secret Manager, non nel repository.

Esempio di secret tipici:

- `app-key`
- `db-connection`
- `db-host`
- `db-port`
- `db-username`
- `db-password`
- `db-database`
- `aws-bucket`
- `aws-default-region`
- `aws-endpoint`
- `aws-access-key-id`
- `aws-secret-access-key`
- `aws-use-path-style-endpoint`
- `max-jobs-per-run`
- `max-attempts`
- `libreoffice-timeout`
- `worker-id`

Esempio di creazione:

```bash
printf '%s' 'YOUR_SECRET_VALUE' | gcloud secrets create your-secret-name --data-file=-
```

Se il secret esiste gia`:

```bash
printf '%s' 'YOUR_NEW_SECRET_VALUE' | gcloud secrets versions add your-secret-name --data-file=-
```

## 6. Build iniziale dell'immagine

```bash
gcloud builds submit \
  --project "${PROJECT_ID}" \
  --tag "${IMAGE_URI}" \
  .
```

## 7. Creazione del Cloud Run Job

```bash
gcloud run jobs create "${JOB_NAME}" \
  --project "${PROJECT_ID}" \
  --region "${REGION}" \
  --image "${IMAGE_URI}" \
  --service-account "${RUNTIME_SERVICE_ACCOUNT_EMAIL}" \
  --tasks 1 \
  --parallelism 1 \
  --max-retries 0 \
  --task-timeout 20m \
  --cpu 1 \
  --memory 2Gi \
  --set-secrets APP_KEY=app-key:latest \
  --set-secrets DB_CONNECTION=db-connection:latest \
  --set-secrets DB_HOST=db-host:latest \
  --set-secrets DB_PORT=db-port:latest \
  --set-secrets DB_USERNAME=db-username:latest \
  --set-secrets DB_PASSWORD=db-password:latest \
  --set-secrets DB_DATABASE=db-database:latest \
  --set-secrets AWS_BUCKET=aws-bucket:latest \
  --set-secrets AWS_DEFAULT_REGION=aws-default-region:latest \
  --set-secrets AWS_ENDPOINT=aws-endpoint:latest \
  --set-secrets AWS_ACCESS_KEY_ID=aws-access-key-id:latest \
  --set-secrets AWS_SECRET_ACCESS_KEY=aws-secret-access-key:latest \
  --set-secrets AWS_USE_PATH_STYLE_ENDPOINT=aws-use-path-style-endpoint:latest \
  --set-secrets MAX_JOBS_PER_RUN=max-jobs-per-run:latest \
  --set-secrets MAX_ATTEMPTS=max-attempts:latest \
  --set-secrets LIBREOFFICE_TIMEOUT=libreoffice-timeout:latest \
  --set-secrets WORKER_ID=worker-id:latest \
  --set-env-vars APP_ENV=production \
  --set-env-vars APP_DEBUG=false \
  --set-env-vars LOG_CHANNEL=stderr \
  --set-env-vars LOG_LEVEL=info \
  --set-env-vars FILESYSTEM_DISK=s3
```

## 8. Esecuzione manuale

```bash
gcloud run jobs execute "${JOB_NAME}" \
  --project "${PROJECT_ID}" \
  --region "${REGION}" \
  --wait
```

## 9. Update dell'immagine

```bash
BUILD_TAG="$(date +%Y%m%d-%H%M%S)"
IMAGE_URI="${REGION}-docker.pkg.dev/${PROJECT_ID}/${REPOSITORY}/${IMAGE_NAME}:${BUILD_TAG}"

gcloud builds submit \
  --project "${PROJECT_ID}" \
  --tag "${IMAGE_URI}" \
  .

gcloud run jobs update "${JOB_NAME}" \
  --project "${PROJECT_ID}" \
  --image "${IMAGE_URI}" \
  --region "${REGION}"
```

## 10. Verifiche utili

Lista job:

```bash
gcloud run jobs list \
  --project "${PROJECT_ID}" \
  --region "${REGION}"
```

Descrizione job:

```bash
gcloud run jobs describe "${JOB_NAME}" \
  --project "${PROJECT_ID}" \
  --region "${REGION}"
```

Log esecuzioni:

```bash
gcloud run jobs executions list \
  --project "${PROJECT_ID}" \
  --region "${REGION}" \
  --job "${JOB_NAME}"
```
