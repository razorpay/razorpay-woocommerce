#!/bin/sh

run_autowooc_suite_when_approved() {
  statusCode=$(curl -c /tmp/cookies -o -s -w "%{http_code}" --location --request GET 'https://deploy-api.razorpay.com/login' \
      --header "Authorization: Bearer ${GIT_TOKEN}")
  cookies="$(cat /tmp/cookies | awk '/SESSION/ { print $NF }')"
  commitId=$(jq --raw-output .pull_request.head.sha "$GITHUB_EVENT_PATH")
  headerCookie="Cookie: SESSION=$cookies"
  skipBVT="false"
  echo "Status Code for fetching spinnaker cookie $statusCode"
  if [ "$statusCode" = 200 ]; then
    pipelines=$(curl --location --request GET "https://deploy.razorpay.com/#/applications/devserve-cmma/executions?startManualExecution=devserve-cmma-test-woocommerce" -H "${headerCookie}" \
     | jq -c '.[] | select(.trigger.parameters.repo | contains("razorpay-woocommerce"))' | jq --raw-output '. | {pr_number: .trigger.parameters.pr_number,id: .id,status: .status,commitId: .trigger.parameters.app_commit_id}| @base64')
    for p in $pipelines; do
      pipeline="$(echo "$p" | base64 -d)"
      pId=$(echo "$pipeline" | jq --raw-output '.id')
      pCommitId=$(echo "$pipeline" | jq --raw-output '.commitId')
      pPRNumber=$(echo "$pipeline" | jq --raw-output '.pr_number')
      pStatus=$(echo "$pipeline" | jq --raw-output '.status')
      if [ "$pPRNumber" = "$PR_NUMBER" ] && ( [ "$pCommitId" != $commitId ] && ( [ "$pStatus" = "RUNNING" ] ||  [ "$pStatus" = "NOT_STARTED" ] )) ; then
        spinnakerCancelRequestStatusCode=$(curl -o -s -w "%{http_code}" --location --request PUT "https://deploy-api.razorpay.com/pipelines/$pId/cancel" \
          -H "${headerCookie}")
        echo "PR number $pPRNumber and Commit id $pCommitId in $pStatus state, this is being cancelled"
        if [ "$spinnakerCancelRequestStatusCode" = 200 ]; then
          echo "Pipeline cancellation succeeded"
        else
          echo "Pipeline cancellation failed"
        fi
      fi
      if [ "$pPRNumber" = "$PR_NUMBER" ] && ( [ "$pCommitId" = $commitId ] && ( [ "$pStatus" = "RUNNING" ] ||  [ "$pStatus" = "NOT_STARTED" ] )) ; then
        skipBVT="true"
      fi
    done
  fi

  URI="https://api.github.com"
  API_HEADER="Accept: application/vnd.github.v3+json"
  AUTH_HEADER="Authorization: token ${GITHUB_TOKEN}"
  action=$(jq --raw-output .action "$GITHUB_EVENT_PATH")
  state=$(jq --raw-output .review.state "$GITHUB_EVENT_PATH")
  number=$(jq --raw-output .pull_request.number "$GITHUB_EVENT_PATH")
  commitId=$(jq --raw-output .pull_request.head.sha "$GITHUB_EVENT_PATH")
  # https://developer.github.com/v3/pulls/reviews/#list-reviews-on-a-pull-request
  body=$(curl -sSL -H "${AUTH_HEADER}" -H "${API_HEADER}" "${URI}/repos/${GITHUB_REPOSITORY}/pulls/${number}/reviews?per_page=100")
  reviews=$(echo "$body" | tr '\r\n' ' ' | jq --raw-output '.[] | {state: .state} | @base64')
  for r in $reviews; do
    review="$(echo "$r" | base64 -d)"
    rState=$(echo "$review" | jq --raw-output '.state')
    if [ "$rState" = "APPROVED" ] && [ "$skipBVT" != "true" ]; then
      echo "Triggering webhook for bvt execution for :" $commitId
      curl -X POST \
      -u github-actions:$SPINNAKER_PASSWORD \
       https://deploy-github-actions.razorpay.com/webhooks/webhook/$WEBHOOK_TRIGGER \
       -H "content-type: application/json" \
       -d "{\"repo\":\"mozart\",\"review\":{\"state\":\"approved\"},\"pull_request\":{\"head\":{ \"sha\":\"$commitId\"},\"number\":\"$number\",\"state\":\"approved\"} }"
      break
    else
      echo "PR is not approved state/ BVT is already running, ignoring for bvt execution"
    fi
  done
}

run_autowooc_suite_devstack_when_approved() {
  statusCode=$(curl -c /tmp/cookies -o -s -w "%{http_code}" --location --request GET 'https://deploy-api.razorpay.com/login' \
      --header "Authorization: Bearer ${GIT_TOKEN}")
  cookies="$(cat /tmp/cookies | awk '/SESSION/ { print $NF }')"
  commitId=$(jq --raw-output .pull_request.head.sha "$GITHUB_EVENT_PATH")
  headerCookie="Cookie: SESSION=$cookies"
  skipBVT="false"
  echo "Status Code for fetching spinnaker cookie $statusCode"
  if [ "$statusCode" = 200 ]; then
    pipelines=$(curl --location --request GET "https://deploy.razorpay.com/#/applications/devserve-cmma/executions?startManualExecution=devserve-cmma-test-woocommerce" -H "${headerCookie}" \
     | jq -c '.[] | select(.trigger.parameters.repo | contains("mozart"))' | jq --raw-output '. | {pr_number: .trigger.parameters.pr_number,id: .id,status: .status,commitId: .trigger.parameters.app_commit_id}| @base64')
    for p in $pipelines; do
      pipeline="$(echo "$p" | base64 -d)"
      pId=$(echo "$pipeline" | jq --raw-output '.id')
      pCommitId=$(echo "$pipeline" | jq --raw-output '.commitId')
      pPRNumber=$(echo "$pipeline" | jq --raw-output '.pr_number')
      pStatus=$(echo "$pipeline" | jq --raw-output '.status')
      if [ "$pPRNumber" = "$PR_NUMBER" ] && ( [ "$pCommitId" != $commitId ] && ( [ "$pStatus" = "RUNNING" ] ||  [ "$pStatus" = "NOT_STARTED" ] )) ; then
        spinnakerCancelRequestStatusCode=$(curl -o -s -w "%{http_code}" --location --request PUT "https://deploy-api.razorpay.com/pipelines/$pId/cancel" \
          -H "${headerCookie}")
        echo "PR number $pPRNumber and Commit id $pCommitId in $pStatus state, this is being cancelled"
        if [ "$spinnakerCancelRequestStatusCode" = 200 ]; then
          echo "Pipeline cancellation succeeded"
        else
          echo "Pipeline cancellation failed"
        fi
      fi
      if [ "$pPRNumber" = "$PR_NUMBER" ] && ( [ "$pCommitId" = $commitId ] && ( [ "$pStatus" = "RUNNING" ] ||  [ "$pStatus" = "NOT_STARTED" ] )) ; then
        skipBVT="true"
      fi
    done
  fi

  URI="https://api.github.com"
  API_HEADER="Accept: application/vnd.github.v3+json"
  AUTH_HEADER="Authorization: token ${GITHUB_TOKEN}"
  action=$(jq --raw-output .action "$GITHUB_EVENT_PATH")
  state=$(jq --raw-output .review.state "$GITHUB_EVENT_PATH")
  number=$(jq --raw-output .pull_request.number "$GITHUB_EVENT_PATH")
  commitId=$(jq --raw-output .pull_request.head.sha "$GITHUB_EVENT_PATH")
  # https://developer.github.com/v3/pulls/reviews/#list-reviews-on-a-pull-request
  body=$(curl -sSL -H "${AUTH_HEADER}" -H "${API_HEADER}" "${URI}/repos/${GITHUB_REPOSITORY}/pulls/${number}/reviews?per_page=100")
  reviews=$(echo "$body" | tr '\r\n' ' ' | jq --raw-output '.[] | {state: .state} | @base64')
  for r in $reviews; do
    review="$(echo "$r" | base64 -d)"
    rState=$(echo "$review" | jq --raw-output '.state')
    if [ "$rState" = "APPROVED" ]; then
      echo "Triggering webhook for bvt execution for :" $commitId
      curl -X POST \
      -u github-actions:$SPINNAKER_PASSWORD \
       https://deploy-github-actions.razorpay.com/webhooks/webhook/$WEBHOOK_TRIGGER \
       -H "content-type: application/json" \
       -d "{\"repo\":\"mozart\",\"review\":{\"state\":\"approved\",\"skip_bvt\":\"$skipBVT\"},\"pull_request\":{\"head\":{ \"sha\":\"$commitId\"},\"number\":\"$number\",\"state\":\"approved\"} }"
      break
    else
      echo "PR is not approved state/ BVT is already running, ignoring for bvt execution"
    fi
  done
}

if [ "${RUN_AutoWooc_DEVSTACK}" = "true" ]; then
  echo "Hi"
  run_autowooc_suite_devstack_when_approved
else
  run_autowooc_suite_when_approved
fi