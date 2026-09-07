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
