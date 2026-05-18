#!/usr/bin/env bash

set -u

PROJECT_ID="${PROJECT_ID:-labor-2023}"
REGION="${REGION:-europe-west8}"
REPOSITORY="${REPOSITORY:-app-images}"
IMAGE_NAME="${IMAGE_NAME:-sodexo-lms-converter}"
JOB_NAME="${JOB_NAME:-sodexo-docx-worker}"
POOL_ID="${POOL_ID:-github-actions}"
PROVIDER_ID="${PROVIDER_ID:-github}"
SERVICE_ACCOUNT_NAME="${SERVICE_ACCOUNT_NAME:-github-actions-deploy}"
SERVICE_ACCOUNT_EMAIL="${SERVICE_ACCOUNT_EMAIL:-${SERVICE_ACCOUNT_NAME}@${PROJECT_ID}.iam.gserviceaccount.com}"
GITHUB_REPOSITORY="${GITHUB_REPOSITORY:-PerroniClaudio/sodexo-lms-converter}"

FAILED=0

pass() {
    printf '[PASS] %s\n' "$1"
}

fail() {
    printf '[FAIL] %s\n' "$1"
    FAILED=1
}

info() {
    printf '[INFO] %s\n' "$1"
}

check_command() {
    if command -v "$1" >/dev/null 2>&1; then
        pass "Command available: $1"
    else
        fail "Command not available: $1"
    fi
}

check_api_enabled() {
    local api="$1"

    if gcloud services list --enabled --project "$PROJECT_ID" --format='value(config.name)' \
        | rg -x "$api" >/dev/null 2>&1; then
        pass "API enabled: $api"
    else
        fail "API not enabled: $api"
    fi
}

check_project() {
    if gcloud projects describe "$PROJECT_ID" --format='value(projectNumber)' >/dev/null 2>&1; then
        PROJECT_NUMBER="$(gcloud projects describe "$PROJECT_ID" --format='value(projectNumber)')"
        pass "Project exists: $PROJECT_ID ($PROJECT_NUMBER)"
    else
        fail "Project not accessible: $PROJECT_ID"
        return
    fi

    local active_project
    active_project="$(gcloud config get-value project 2>/dev/null || true)"

    if [ "$active_project" = "$PROJECT_ID" ]; then
        pass "Active gcloud project matches: $PROJECT_ID"
    else
        fail "Active gcloud project is '$active_project', expected '$PROJECT_ID'"
    fi
}

check_service_account() {
    if gcloud iam service-accounts describe "$SERVICE_ACCOUNT_EMAIL" --project "$PROJECT_ID" >/dev/null 2>&1; then
        pass "Service account exists: $SERVICE_ACCOUNT_EMAIL"
    else
        fail "Service account missing: $SERVICE_ACCOUNT_EMAIL"
    fi
}

check_project_role() {
    local role="$1"

    if gcloud projects get-iam-policy "$PROJECT_ID" \
        --flatten='bindings[].members' \
        --filter="bindings.role:$role AND bindings.members:serviceAccount:$SERVICE_ACCOUNT_EMAIL" \
        --format='value(bindings.role)' \
        | rg -x "$role" >/dev/null 2>&1; then
        pass "Project role granted: $role"
    else
        fail "Project role missing: $role"
    fi
}

check_workload_identity_pool() {
    if gcloud iam workload-identity-pools describe "$POOL_ID" \
        --project "$PROJECT_ID" \
        --location='global' >/dev/null 2>&1; then
        pass "Workload Identity Pool exists: $POOL_ID"
    else
        fail "Workload Identity Pool missing: $POOL_ID"
    fi
}

check_workload_identity_provider() {
    if gcloud iam workload-identity-pools providers describe "$PROVIDER_ID" \
        --project "$PROJECT_ID" \
        --location='global' \
        --workload-identity-pool="$POOL_ID" >/dev/null 2>&1; then
        pass "Workload Identity Provider exists: $PROVIDER_ID"
        info "Expected GitHub secret GCP_WORKLOAD_IDENTITY_PROVIDER: projects/$PROJECT_NUMBER/locations/global/workloadIdentityPools/$POOL_ID/providers/$PROVIDER_ID"
    else
        fail "Workload Identity Provider missing: $PROVIDER_ID"
    fi
}

check_workload_identity_binding() {
    local member
    member="principalSet://iam.googleapis.com/projects/$PROJECT_NUMBER/locations/global/workloadIdentityPools/$POOL_ID/attribute.repository/$GITHUB_REPOSITORY"

    if gcloud iam service-accounts get-iam-policy "$SERVICE_ACCOUNT_EMAIL" \
        --project "$PROJECT_ID" \
        --flatten='bindings[].members' \
        --filter="bindings.role:roles/iam.workloadIdentityUser AND bindings.members:$member" \
        --format='value(bindings.role)' \
        | rg -x 'roles/iam.workloadIdentityUser' >/dev/null 2>&1; then
        pass "Workload Identity binding granted for $GITHUB_REPOSITORY"
    else
        fail "Workload Identity binding missing for $GITHUB_REPOSITORY"
    fi
}

check_artifact_registry_repository() {
    if gcloud artifacts repositories describe "$REPOSITORY" \
        --project "$PROJECT_ID" \
        --location "$REGION" >/dev/null 2>&1; then
        pass "Artifact Registry repository exists: $REPOSITORY"
        info "Expected image prefix: ${REGION}-docker.pkg.dev/${PROJECT_ID}/${REPOSITORY}/${IMAGE_NAME}"
    else
        fail "Artifact Registry repository missing: $REPOSITORY"
    fi
}

check_cloud_run_job() {
    if gcloud run jobs describe "$JOB_NAME" \
        --project "$PROJECT_ID" \
        --region "$REGION" >/dev/null 2>&1; then
        pass "Cloud Run Job exists: $JOB_NAME"
    else
        fail "Cloud Run Job missing: $JOB_NAME"
    fi
}

main() {
    info "Checking Google Cloud setup for GitHub Actions"
    info "Project: $PROJECT_ID"
    info "Region: $REGION"
    info "Repository: $GITHUB_REPOSITORY"

    check_command gcloud
    check_command rg
    check_project

    check_api_enabled run.googleapis.com
    check_api_enabled artifactregistry.googleapis.com
    check_api_enabled cloudbuild.googleapis.com
    check_api_enabled iamcredentials.googleapis.com

    check_service_account
    check_project_role roles/cloudbuild.builds.editor
    check_project_role roles/artifactregistry.writer
    check_project_role roles/run.developer

    check_workload_identity_pool
    check_workload_identity_provider

    if [ -n "${PROJECT_NUMBER:-}" ]; then
        check_workload_identity_binding
    fi

    check_artifact_registry_repository
    check_cloud_run_job

    if [ "$FAILED" -ne 0 ]; then
        printf '\nOne or more checks failed.\n'
        exit 1
    fi

    printf '\nAll checks passed.\n'
}

main "$@"
