# Google Cloud Deployment Notes

Questi documenti spiegano come ripetere il setup fatto per questo progetto senza inserire dati sensibili nel repository.

File disponibili:

- [Cloud Run Job Setup](./cloud-run-job-setup.md)
- [GitHub Actions Deployment Setup](./github-actions-deployment.md)
- [Laravel Cloud Trigger Setup](./laravel-cloud-trigger.md)

Valori da sostituire:

- `PROJECT_ID`
- `PROJECT_NUMBER`
- `REGION`
- `REPOSITORY`
- `IMAGE_NAME`
- `JOB_NAME`
- `SERVICE_ACCOUNT_EMAIL`
- `OWNER/REPO`

Prima di lanciare i comandi, controlla sempre il progetto attivo:

```bash
gcloud config get-value project
```
