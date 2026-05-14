# Guida Step By Step: Deploy su Google Cloud Run Job con Secret Manager

Questa applicazione va pubblicata come **Cloud Run Job**.

Non usare **Cloud Run Service**, perché questa app non espone una porta HTTP: esegue un comando batch Laravel.

Comando eseguito dal container:

```bash
php artisan convert-docx-to-pdf --no-interaction
```

## 1. Cosa Devi Avere Prima

Ti servono:

- un progetto Google Cloud
- `gcloud` installato sul tuo computer
- billing attivo sul progetto
- Docker funzionante in locale, oppure Google Cloud Build
- le credenziali del database MySQL
- le credenziali S3 del bucket dove leggi/scrivi i file

## 2. Variabili che Devi Sostituire

Nel terminale definisci queste variabili, una riga alla volta:

```bash
PROJECT_ID="metti-il-project-id"
REGION="europe-west1"
REPOSITORY="app-images"
IMAGE_NAME="sodexo-lms-converter"
JOB_NAME="sodexo-docx-worker"
SERVICE_ACCOUNT_NAME="cloud-run-job-sa"
SERVICE_ACCOUNT_EMAIL="$SERVICE_ACCOUNT_NAME@$PROJECT_ID.iam.gserviceaccount.com"
IMAGE_URI="$REGION-docker.pkg.dev/$PROJECT_ID/$REPOSITORY/$IMAGE_NAME:latest"
```

Per controllare:

```bash
echo "$PROJECT_ID"
echo "$IMAGE_URI"
```

## 3. Login a Google Cloud

```bash
gcloud auth login
gcloud config set project "$PROJECT_ID"
```

Controlla il progetto attivo:

```bash
gcloud config get-value project
```

## 4. Abilita le API Necessarie

```bash
gcloud services enable \
  run.googleapis.com \
  secretmanager.googleapis.com \
  artifactregistry.googleapis.com \
  cloudbuild.googleapis.com
```

## 5. Crea il Repository Docker

```bash
gcloud artifacts repositories create "$REPOSITORY" \
  --repository-format=docker \
  --location="$REGION"
```

Se esiste già, puoi ignorare l’errore.

## 6. Crea il Service Account del Job

```bash
gcloud iam service-accounts create "$SERVICE_ACCOUNT_NAME" \
  --display-name="Cloud Run Job Service Account"
```

## 7. Dai al Job il Permesso di Leggere i Secret

```bash
gcloud projects add-iam-policy-binding "$PROJECT_ID" \
  --member="serviceAccount:$SERVICE_ACCOUNT_EMAIL" \
  --role="roles/secretmanager.secretAccessor"
```

## 8. Crea i Secret in Google Secret Manager

In questa guida mettiamo in Secret Manager tutte le variabili runtime che vuoi usare nel job.

Secret da creare:

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

## 9. Crea una Cartella Temporanea Fuori dal Repo

Usa una cartella nella tua home, fuori da questo repository:

```bash
mkdir -p ~/.gcloud-secrets-temp
```

Questa cartella non è dentro il progetto, quindi Git non la traccia.

Non usare più percorsi tipo `/tmp/app-key.txt`.
In tutti i passaggi sotto devi usare solo file dentro `~/.gcloud-secrets-temp`.

## 10. Crea i File Temporanei con i Valori

Esegui questi comandi e sostituisci i placeholder:

```bash
printf '%s' 'base64:METTI_QUI_LA_TUA_APP_KEY' > ~/.gcloud-secrets-temp/app-key.txt
printf '%s' 'mysql' > ~/.gcloud-secrets-temp/db-connection.txt
printf '%s' 'METTI_QUI_DB_HOST' > ~/.gcloud-secrets-temp/db-host.txt
printf '%s' '3306' > ~/.gcloud-secrets-temp/db-port.txt
printf '%s' 'METTI_QUI_DB_USERNAME' > ~/.gcloud-secrets-temp/db-username.txt
printf '%s' 'METTI_QUI_DB_PASSWORD' > ~/.gcloud-secrets-temp/db-password.txt
printf '%s' 'METTI_QUI_DB_DATABASE' > ~/.gcloud-secrets-temp/db-database.txt
printf '%s' 'METTI_QUI_BUCKET' > ~/.gcloud-secrets-temp/aws-bucket.txt
printf '%s' 'METTI_QUI_AWS_REGION' > ~/.gcloud-secrets-temp/aws-default-region.txt
printf '%s' 'METTI_QUI_AWS_ENDPOINT' > ~/.gcloud-secrets-temp/aws-endpoint.txt
printf '%s' 'METTI_QUI_AWS_ACCESS_KEY_ID' > ~/.gcloud-secrets-temp/aws-access-key-id.txt
printf '%s' 'METTI_QUI_AWS_SECRET_ACCESS_KEY' > ~/.gcloud-secrets-temp/aws-secret-access-key.txt
printf '%s' 'false' > ~/.gcloud-secrets-temp/aws-use-path-style-endpoint.txt
printf '%s' '10' > ~/.gcloud-secrets-temp/max-jobs-per-run.txt
printf '%s' '3' > ~/.gcloud-secrets-temp/max-attempts.txt
printf '%s' '120' > ~/.gcloud-secrets-temp/libreoffice-timeout.txt
printf '%s' 'worker-1' > ~/.gcloud-secrets-temp/worker-id.txt
```

Se non usi `AWS_ENDPOINT`, puoi lasciarlo vuoto:

```bash
printf '%s' '' > ~/.gcloud-secrets-temp/aws-endpoint.txt
```

Se non vuoi fissare un `WORKER_ID` manuale, puoi comunque creare un valore semplice:

```bash
printf '%s' 'cloud-run-worker' > ~/.gcloud-secrets-temp/worker-id.txt
```

## 11. Crea i Secret

Prima di lanciare questi comandi, verifica che i file esistano davvero:

```bash
ls -la ~/.gcloud-secrets-temp
```

```bash
gcloud secrets create app-key --data-file="$HOME/.gcloud-secrets-temp/app-key.txt"
gcloud secrets create db-connection --data-file="$HOME/.gcloud-secrets-temp/db-connection.txt"
gcloud secrets create db-host --data-file="$HOME/.gcloud-secrets-temp/db-host.txt"
gcloud secrets create db-port --data-file="$HOME/.gcloud-secrets-temp/db-port.txt"
gcloud secrets create db-username --data-file="$HOME/.gcloud-secrets-temp/db-username.txt"
gcloud secrets create db-password --data-file="$HOME/.gcloud-secrets-temp/db-password.txt"
gcloud secrets create db-database --data-file="$HOME/.gcloud-secrets-temp/db-database.txt"
gcloud secrets create aws-bucket --data-file="$HOME/.gcloud-secrets-temp/aws-bucket.txt"
gcloud secrets create aws-default-region --data-file="$HOME/.gcloud-secrets-temp/aws-default-region.txt"
gcloud secrets create aws-endpoint --data-file="$HOME/.gcloud-secrets-temp/aws-endpoint.txt"
gcloud secrets create aws-access-key-id --data-file="$HOME/.gcloud-secrets-temp/aws-access-key-id.txt"
gcloud secrets create aws-secret-access-key --data-file="$HOME/.gcloud-secrets-temp/aws-secret-access-key.txt"
gcloud secrets create aws-use-path-style-endpoint --data-file="$HOME/.gcloud-secrets-temp/aws-use-path-style-endpoint.txt"
gcloud secrets create max-jobs-per-run --data-file="$HOME/.gcloud-secrets-temp/max-jobs-per-run.txt"
gcloud secrets create max-attempts --data-file="$HOME/.gcloud-secrets-temp/max-attempts.txt"
gcloud secrets create libreoffice-timeout --data-file="$HOME/.gcloud-secrets-temp/libreoffice-timeout.txt"
gcloud secrets create worker-id --data-file="$HOME/.gcloud-secrets-temp/worker-id.txt"
```

## 12. Se un Secret Esiste Già, Aggiungi una Nuova Versione

Non ricrearlo. Aggiungi una nuova versione:

```bash
printf '%s' 'NUOVO_VALORE' | gcloud secrets versions add NOME_SECRET --data-file=-
```

Esempi:

```bash
printf '%s' 'nuova-password-db' | gcloud secrets versions add db-password --data-file=-
printf '%s' '20' | gcloud secrets versions add max-jobs-per-run --data-file=-
printf '%s' '180' | gcloud secrets versions add libreoffice-timeout --data-file=-
```

## 13. Build e Push dell’Immagine

```bash
gcloud builds submit --tag "$IMAGE_URI"
```

Questo comando:

- builda l’immagine
- la pubblica su Artifact Registry

## 14. Crea il Job Cloud Run

Questo passaggio **crea per la prima volta** il job `sodexo-docx-worker` su Cloud Run.

Se questo comando non viene eseguito con successo, il comando `gcloud run jobs execute ...` fallirà con errore `NOT_FOUND`.

Qui usiamo:

- `--set-secrets` per tutte le env del job che hai richiesto
- `--set-env-vars` solo per i flag runtime non sensibili e costanti dell’ambiente

```bash
gcloud run jobs create "$JOB_NAME" \
  --image "$IMAGE_URI" \
  --region "$REGION" \
  --service-account "$SERVICE_ACCOUNT_EMAIL" \
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

Se il comando va a buon fine, controlla subito che il job esista davvero:

```bash
gcloud run jobs list --region "$REGION"
```

Devi vedere il job con nome:

```bash
$JOB_NAME
```

## 15. Esegui il Job

Esegui questo comando **solo dopo** che il job è stato creato con successo nello step 14.

```bash
gcloud run jobs execute "$JOB_NAME" \
  --region "$REGION" \
  --wait
```

Con `--wait` il terminale aspetta la fine del job.

## 16. Controlla lo Storico delle Esecuzioni

```bash
gcloud run jobs executions list \
  --job "$JOB_NAME" \
  --region "$REGION"
```

## 17. Leggi i Log

```bash
gcloud logging read \
  'resource.type="cloud_run_job" AND resource.labels.job_name="'"$JOB_NAME"'"' \
  --limit=100 \
  --format="value(textPayload)"
```

## 18. Aggiornare il Job Dopo una Nuova Build

```bash
gcloud builds submit --tag "$IMAGE_URI"
```

Poi:

```bash
gcloud run jobs update "$JOB_NAME" \
  --image "$IMAGE_URI" \
  --region "$REGION"
```

E poi rilancia:

```bash
gcloud run jobs execute "$JOB_NAME" \
  --region "$REGION" \
  --wait
```

## 19. Aggiornare un Secret

Esempi:

```bash
printf '%s' 'NUOVA_PASSWORD' | gcloud secrets versions add db-password --data-file=-
printf '%s' '5' | gcloud secrets versions add max-attempts --data-file=-
printf '%s' '300' | gcloud secrets versions add libreoffice-timeout --data-file=-
```

Alla prossima esecuzione del job, usando `latest`, verrà letta l’ultima versione.

## 20. Se Usi Cloud SQL Invece di un DB Esterno

Se il database è su **Cloud SQL MySQL**, il job va aggiornato così:

```bash
gcloud run jobs update "$JOB_NAME" \
  --region "$REGION" \
  --set-cloudsql-instances "$PROJECT_ID:$REGION:INSTANCE_NAME"
```

In quel caso il valore di `db-host` deve essere:

```bash
/cloudsql/PROJECT_ID:REGION:INSTANCE_NAME
```

Esempio:

```bash
printf '%s' '/cloudsql/my-project:europe-west1:my-mysql' | gcloud secrets versions add db-host --data-file=-
```

## 21. Variabili Ora Supportate Davvero dall’App

L’app ora legge davvero anche queste env:

- `MAX_ATTEMPTS`
- `LIBREOFFICE_TIMEOUT`

Comportamento:

- `MAX_JOBS_PER_RUN` controlla quanti job processare per esecuzione
- `MAX_ATTEMPTS` è il fallback applicativo per `max_attempts` quando il record job non ha un valore valido
- `LIBREOFFICE_TIMEOUT` controlla il timeout del processo `soffice`
- `WORKER_ID` imposta l’identificativo del worker

## 22. Errori Comuni

### `Permission denied` sui secret

Manca questo ruolo al service account del job:

- `roles/secretmanager.secretAccessor`

### Il job parte ma non si collega al database

Controlla:

- `DB_CONNECTION`
- `DB_HOST`
- `DB_PORT`
- `DB_DATABASE`
- `DB_USERNAME`
- `DB_PASSWORD`

### Il job non legge S3

Controlla:

- `AWS_ACCESS_KEY_ID`
- `AWS_SECRET_ACCESS_KEY`
- `AWS_DEFAULT_REGION`
- `AWS_BUCKET`
- `AWS_ENDPOINT`
- `AWS_USE_PATH_STYLE_ENDPOINT`

### LibreOffice impiega troppo e il job si interrompe

Aggiorna il secret:

```bash
printf '%s' '300' | gcloud secrets versions add libreoffice-timeout --data-file=-
```

## 23. Valori Consigliati per Partire

Ti consiglio:

- `--tasks 1`
- `--parallelism 1`
- `--cpu 1`
- `--memory 2Gi`
- `--task-timeout 20m`
- `MAX_JOBS_PER_RUN=10`
- `MAX_ATTEMPTS=3`
- `LIBREOFFICE_TIMEOUT=120`

## 24. Checklist Finale

Controlla:

- API abilitate
- repository Docker creato
- service account creato
- permesso `secretAccessor` assegnato
- secret creati
- immagine buildata e pushata
- job Cloud Run creato
- prima esecuzione completata
- log letti

## 25. Pulizia Finale dei File Temporanei

Quando hai finito di creare i secret, puoi cancellare i file locali:

```bash
rm -rf ~/.gcloud-secrets-temp
```

## 26. Link Ufficiali

- [Create jobs](https://cloud.google.com/run/docs/create-jobs)
- [Execute jobs](https://cloud.google.com/run/docs/execute/jobs)
- [Configure secrets for jobs](https://cloud.google.com/run/docs/configuring/jobs/secrets)
- [Create a secret](https://cloud.google.com/secret-manager/docs/creating-and-accessing-secrets)
- [Add a secret version](https://cloud.google.com/secret-manager/docs/add-secret-version)
