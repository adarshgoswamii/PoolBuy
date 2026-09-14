# Dev helper for driving the OpenCart admin over HTTP during development.
#
# OpenCart's admin login is a two-step handshake: the login page issues a
# single-use `login_token`, which must be presented with the credentials; the
# successful response then carries the `user_token` used by every subsequent
# admin request.
#
# Usage:
#   . tools\poolbuy\admin.ps1
#   $admin = Connect-OcAdmin
#   Invoke-OcAdmin $admin 'extension/module.install' @{ extension = 'poolbuy'; code = 'poolbuy' }

$OcBaseUrl = 'http://localhost'

function Connect-OcAdmin {
    param(
        [string] $BaseUrl  = $OcBaseUrl,
        [string] $Username = 'admin',
        [string] $Password = 'admin'
    )

    $session = New-Object Microsoft.PowerShell.Commands.WebRequestSession

    $page = Invoke-WebRequest -Uri "$BaseUrl/admin/index.php?route=common/login" -WebSession $session -UseBasicParsing
    if ($page.Content -notmatch 'login_token=([0-9a-zA-Z]+)') {
        throw 'Could not read login_token from the admin login page.'
    }
    $loginToken = $Matches[1]

    $response = Invoke-WebRequest -Uri "$BaseUrl/admin/index.php?route=common/login.login&login_token=$loginToken" `
        -Method POST -Body @{ username = $Username; password = $Password } `
        -WebSession $session -UseBasicParsing

    if ($response.Content -notmatch 'user_token=([0-9a-f]+)') {
        throw "Admin login failed. Response: $($response.Content)"
    }

    return [pscustomobject]@{
        BaseUrl   = $BaseUrl
        Session   = $session
        UserToken = $Matches[1]
    }
}

function Invoke-OcAdmin {
    param(
        [Parameter(Mandatory = $true)] $Admin,
        [Parameter(Mandatory = $true)] [string] $Route,
        [hashtable] $Query  = @{},
        [hashtable] $Body   = $null,
        [string]    $Method = 'GET',
        # Pre-encoded application/x-www-form-urlencoded string. Needed for repeated
        # fields such as pool_tier[0][price], which a hashtable cannot express.
        [string]    $RawBody = $null
    )

    $qs = "route=$Route&user_token=$($Admin.UserToken)"
    foreach ($key in $Query.Keys) {
        $qs += "&$key=" + [uri]::EscapeDataString([string]$Query[$key])
    }

    $uri = "$($Admin.BaseUrl)/admin/index.php?$qs"

    if ($RawBody) {
        return Invoke-WebRequest -Uri $uri -Method POST -Body $RawBody `
            -ContentType 'application/x-www-form-urlencoded' -WebSession $Admin.Session -UseBasicParsing
    }

    if ($Body) {
        return Invoke-WebRequest -Uri $uri -Method POST -Body $Body -WebSession $Admin.Session -UseBasicParsing
    }

    return Invoke-WebRequest -Uri $uri -Method $Method -WebSession $Admin.Session -UseBasicParsing
}

# Backtick-quoted identifiers are needed for reserved words such as `key`, but a
# literal backtick is awkward to embed in a PowerShell double-quoted string.
$OcTick = [char]96

# Runs a query against the MySQL container and returns the first scalar value as
# a string, or $null when nothing matched.
#
# MYSQL_PWD is used instead of -p so mysql writes nothing to stderr, which would
# otherwise trip ErrorActionPreference = 'Stop' in calling scripts.
function Get-OcSqlScalar {
    param(
        [Parameter(Mandatory = $true)] [string] $Sql
    )

    $rows = docker compose exec -T -e MYSQL_PWD=opencart mysql mysql -uroot opencart -N -B -e $Sql

    foreach ($row in @($rows)) {
        $value = [string]$row
        if ($value.Trim() -ne '') {
            return $value.Trim()
        }
    }

    return $null
}

function Get-OcSqlInt {
    param(
        [Parameter(Mandatory = $true)] [string] $Sql
    )

    $value = Get-OcSqlScalar -Sql $Sql

    if ($null -eq $value -or $value -notmatch '^-?\d+$') {
        return 0
    }

    return [int]$value
}

function Invoke-OcSql {
    param(
        [Parameter(Mandatory = $true)] [string] $Sql
    )

    docker compose exec -T -e MYSQL_PWD=opencart mysql mysql -uroot opencart -e $Sql | Out-Null
}

<#
.SYNOPSIS
    Writes a single module_poolbuy setting, idempotently.

.DESCRIPTION
    oc_setting has ONLY a primary key on setting_id. There is no unique index on
    (store_id, code, key), so "INSERT ... ON DUPLICATE KEY UPDATE" never fires and
    silently APPENDS a duplicate row on every call.

    That is not a harmless untidiness. OpenCart's config loader reads every row for
    the store and assigns as it goes, so the highest setting_id wins. A suite that
    changed a setting, asserted against it and then "restored" it could leave the
    test value as the last row and poison every later suite - which is exactly how a
    stale webhook secret once made five payment checks fail.

    Delete then insert, so exactly one row can exist per key.
#>
function Set-OcPoolbuySetting {
    param(
        [Parameter(Mandatory = $true)] [string] $Key,
        [Parameter(Mandatory = $true)] [AllowEmptyString()] [string] $Value
    )

    $tick = [char]96
    $escaped = $Value.Replace('\', '\\').Replace("'", "\'")

    Invoke-OcSql "DELETE FROM oc_setting WHERE store_id=0 AND code='module_poolbuy' AND $tick$('key')$tick='$Key';"
    Invoke-OcSql "INSERT INTO oc_setting (store_id, code, $tick$('key')$tick, value, serialized) VALUES (0, 'module_poolbuy', '$Key', '$escaped', 0);"
}

<#
.SYNOPSIS
    Counts module_poolbuy setting keys that have more than one row.

.DESCRIPTION
    Used as a guard assertion: any value above zero means something is appending
    duplicate setting rows again, and the effective configuration has become
    whichever row happens to sort last.
#>
function Get-OcDuplicateSettingCount {
    $tick = [char]96

    return Get-OcSqlInt "SELECT COUNT(*) FROM (SELECT 1 FROM oc_setting WHERE code='module_poolbuy' GROUP BY store_id, $tick$('key')$tick HAVING COUNT(*) > 1) d;"
}
