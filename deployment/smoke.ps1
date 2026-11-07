param(
    [string]$Backend = 'https://cfabs-1.onrender.com',
    [string]$Frontend = 'https://cfabs-frontend.vercel.app'
)
$ErrorActionPreference = 'Stop'
$Backend = $Backend.TrimEnd('/')
$Frontend = $Frontend.TrimEnd('/')
$site = Invoke-WebRequest $Frontend -SkipHttpErrorCheck -TimeoutSec 60
if ($site.StatusCode -ne 200) { throw 'Frontend did not return HTTP 200.' }
$health = Invoke-WebRequest "$Backend/healthz.php" -SkipHttpErrorCheck -TimeoutSec 60
if ($health.StatusCode -ne 200 -or ($health.Content | ConvertFrom-Json).status -ne 'ok') { throw 'Backend health check failed; verify the latest backend was deployed.' }
$preflight = Invoke-WebRequest "$Backend/api/auth/me" -Method Options -Headers @{ Origin=$Frontend; 'Access-Control-Request-Method'='GET'; 'Access-Control-Request-Headers'='Authorization' } -SkipHttpErrorCheck -TimeoutSec 60
if ($preflight.StatusCode -ne 204 -or [string]$preflight.Headers['Access-Control-Allow-Origin'] -ne $Frontend) { throw 'Backend CORS preflight failed.' }
$auth = Invoke-WebRequest "$Backend/api/auth/me" -Headers @{Origin=$Frontend} -SkipHttpErrorCheck -TimeoutSec 60
if ($auth.StatusCode -ne 401 -or [string]$auth.Headers['Access-Control-Allow-Origin'] -ne $Frontend) { throw 'Expected an authenticated API route returning HTTP 401 with CORS headers.' }
Write-Output 'Frontend, PHP liveness, CORS and unauthenticated API checks passed.'
