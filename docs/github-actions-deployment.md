# GitHub Actions Deployment Setup

Questa guida documenta il setup della GitHub Action che builda l'immagine e aggiorna il Cloud Run Job.

Workflow nel repository:

- [.github/workflows/google-cloud-build.yml](/Users/claudioperroni/Herd/sodexo-lms-converter/.github/workflows/google-cloud-build.yml:1)

Script di verifica:

- [scripts/check-google-cloud-github-actions.sh](/Users/claudioperroni/Herd/sodexo-lms-converter/scripts/check-google-cloud-github-actions.sh:1)

## 1. Variabili di lavoro

```bash
PROJECT_ID="YOUR_PROJECT_ID"
PROJECT_NUMBER="$(gcloud projects describe "${PROJECT_ID}" --format='value(projectNumber)')"
POOL_ID="github-actions"
PROVIDER_ID="github"
GITHUB_REPOSITORY="OWNER/REPO"
DEPLOY_SERVICE_ACCOUNT_NAME="github-actions-deploy"
DEPLOY_SERVICE_ACCOUNT_EMAIL="${DEPLOY_SERVICE_ACCOUNT_NAME}@${PROJECT_ID}.iam.gserviceaccount.com"
COMPUTE_DEFAULT_SERVICE_ACCOUNT="${PROJECT_NUMBER}-compute@developer.gserviceaccount.com"
JOB_RUNTIME_SERVICE_ACCOUNT="YOUR_CLOUD_RUN_JOB_RUNTIME_SERVICE_ACCOUNT_EMAIL"
REGION="YOUR_REGION"
REPOSITORY="YOUR_ARTIFACT_REGISTRY_REPOSITORY"
IMAGE_NAME="YOUR_IMAGE_NAME"
JOB_NAME="YOUR_JOB_NAME"
```

## 2. Service account della GitHub Action

```bash
gcloud iam service-accounts create "${DEPLOY_SERVICE_ACCOUNT_NAME}" \
  --project "${PROJECT_ID}" \
  --display-name "GitHub Actions deploy"
```

## 3. Workload Identity Pool e Provider

```bash
gcloud iam workload-identity-pools create "${POOL_ID}" \
  --project "${PROJECT_ID}" \
  --location global \
  --display-name "GitHub Actions"
```

```bash
gcloud iam workload-identity-pools providers create-oidc "${PROVIDER_ID}" \
  --project "${PROJECT_ID}" \
  --location global \
  --workload-identity-pool "${POOL_ID}" \
  --display-name "GitHub provider" \
  --issuer-uri "https://token.actions.githubusercontent.com" \
  --attribute-mapping "google.subject=assertion.sub,attribute.repository=assertion.repository,attribute.ref=assertion.ref" \
  --attribute-condition "attribute.repository == '${GITHUB_REPOSITORY}' && attribute.ref == 'refs/heads/main'"
```

## 4. Binding Workload Identity

```bash
gcloud iam service-accounts add-iam-policy-binding "${DEPLOY_SERVICE_ACCOUNT_EMAIL}" \
  --project "${PROJECT_ID}" \
  --member "principalSet://iam.googleapis.com/projects/${PROJECT_NUMBER}/locations/global/workloadIdentityPools/${POOL_ID}/attribute.repository/${GITHUB_REPOSITORY}" \
  --role "roles/iam.workloadIdentityUser"
```

## 5. Ruoli progetto per la GitHub Action

```bash
gcloud projects add-iam-policy-binding "${PROJECT_ID}" \
  --member "serviceAccount:${DEPLOY_SERVICE_ACCOUNT_EMAIL}" \
  --role "roles/cloudbuild.builds.editor"

gcloud projects add-iam-policy-binding "${PROJECT_ID}" \
  --member "serviceAccount:${DEPLOY_SERVICE_ACCOUNT_EMAIL}" \
  --role "roles/artifactregistry.writer"

gcloud projects add-iam-policy-binding "${PROJECT_ID}" \
  --member "serviceAccount:${DEPLOY_SERVICE_ACCOUNT_EMAIL}" \
  --role "roles/run.developer"

gcloud projects add-iam-policy-binding "${PROJECT_ID}" \
  --member "serviceAccount:${DEPLOY_SERVICE_ACCOUNT_EMAIL}" \
  --role "roles/serviceusage.serviceUsageConsumer"
```

## 6. Permesso actAs per Cloud Build

Cloud Build puo` usare il service account Compute Engine di default del progetto. La GitHub Action deve poter fare `actAs` su quel service account.

```bash
gcloud iam service-accounts add-iam-policy-binding "${COMPUTE_DEFAULT_SERVICE_ACCOUNT}" \
  --project "${PROJECT_ID}" \
  --member "serviceAccount:${DEPLOY_SERVICE_ACCOUNT_EMAIL}" \
  --role "roles/iam.serviceAccountUser"
```

## 7. Permesso actAs sul runtime del Cloud Run Job

La GitHub Action aggiorna un job che gira con un service account runtime. Serve `roles/iam.serviceAccountUser` anche su quel service account.

```bash
gcloud iam service-accounts add-iam-policy-binding "${JOB_RUNTIME_SERVICE_ACCOUNT}" \
  --project "${PROJECT_ID}" \
  --member "serviceAccount:${DEPLOY_SERVICE_ACCOUNT_EMAIL}" \
  --role "roles/iam.serviceAccountUser"
```

## 8. Secrets GitHub Actions

Repository GitHub:

`Settings` -> `Secrets and variables` -> `Actions`

Secrets da creare:

- `GCP_WORKLOAD_IDENTITY_PROVIDER`
- `GCP_SERVICE_ACCOUNT`

Valori:

`GCP_WORKLOAD_IDENTITY_PROVIDER`

```text
projects/PROJECT_NUMBER/locations/global/workloadIdentityPools/POOL_ID/providers/PROVIDER_ID
```

`GCP_SERVICE_ACCOUNT`

```text
github-actions-deploy@PROJECT_ID.iam.gserviceaccount.com
```

## 9. Variables GitHub Actions

Variables da creare:

- `GCP_PROJECT_ID`
- `GCP_REGION`
- `GCP_ARTIFACT_REGISTRY_REPOSITORY`
- `GCP_IMAGE_NAME`
- `CLOUD_RUN_JOB`
- `CLOUD_RUN_JOB_REGION`

Esempio:

```text
GCP_PROJECT_ID=YOUR_PROJECT_ID
GCP_REGION=YOUR_REGION
GCP_ARTIFACT_REGISTRY_REPOSITORY=YOUR_ARTIFACT_REGISTRY_REPOSITORY
GCP_IMAGE_NAME=YOUR_IMAGE_NAME
CLOUD_RUN_JOB=YOUR_JOB_NAME
CLOUD_RUN_JOB_REGION=YOUR_REGION
```

## 10. Verifica setup

Usa lo script di check:

```bash
./scripts/check-google-cloud-github-actions.sh
```

Con override:

```bash
PROJECT_ID="YOUR_PROJECT_ID" \
REGION="YOUR_REGION" \
REPOSITORY="YOUR_ARTIFACT_REGISTRY_REPOSITORY" \
IMAGE_NAME="YOUR_IMAGE_NAME" \
JOB_NAME="YOUR_JOB_NAME" \
GITHUB_REPOSITORY="OWNER/REPO" \
./scripts/check-google-cloud-github-actions.sh
```

## 11. Primo test

Fai push su `main` oppure usa `workflow_dispatch`.

Controlli da fare:

- la build Cloud Build viene creata
- l'immagine viene pubblicata su Artifact Registry
- il Cloud Run Job viene aggiornato con la nuova immagine
