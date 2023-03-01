#!/bin/sh

run_autowooc_suite_when_approved() {
  statusCode=$(curl -c /tmp/cookies -o -s -w "%{http_code}" --location --request GET 'https://deploy-api.razorpay.com/login' \
      --header "Authorization: Bearer ${GIT_TOKEN}")
  cookies="$(cat /tmp/cookies | awk '/SESSION/ { print $NF }')"
  commitId=$(jq --raw-output .pull_request.head.sha "$GITHUB_EVENT_PATH")
  headerCookie="Cookie: SESSION=$cookies"
  skipBVT="false"
  echo "Status Code for fetching spinnaker cookie $statusCode"
}

run_autowooc_suite_devstack_when_approved() {
  statusCode=$(curl -c /tmp/cookies -o -s -w "%{http_code}" --location --request GET 'https://deploy-api.razorpay.com/login' \
      --header "Authorization: Bearer ${GIT_TOKEN}")
  cookies="$(cat /tmp/cookies | awk '/SESSION/ { print $NF }')"
  commitId=$(jq --raw-output .pull_request.head.sha "$GITHUB_EVENT_PATH")
  headerCookie="Cookie: SESSION=$cookies"
  skipBVT="false"
  echo "Status Code for fetching spinnaker cookie $statusCode"
}

if [ "${RUN_AutoWooc_DEVSTACK}" = "true" ]; then
  run_autowooc_suite_devstack_when_approved
else
  run_autowooc_suite_when_approved
fi