# One-off: add aria-hidden="true" to decorative FontAwesome <i> tags that lack it.
#
# Deliberately narrow: only matches <i class="fa-..."></i> with NO other attributes,
# so it can never disturb a tag that already carries aria-hidden, an id, or a style.

$ErrorActionPreference = 'Stop'

$targets = @(
    'upload\extension\poolbuy\admin\view\template',
    'upload\extension\poolbuy\catalog\view\template'
)

$utf8NoBom = New-Object System.Text.UTF8Encoding($false)
$pattern = '<i(\s+class="fa-[^"]*")\s*>'
$total = 0

foreach ($dir in $targets) {
    Get-ChildItem -Recurse -Path $dir -Filter *.twig | ForEach-Object {
        $path = $_.FullName
        $before = [System.IO.File]::ReadAllText($path)
        $count = [regex]::Matches($before, $pattern).Count

        if ($count -eq 0) { return }

        $after = [regex]::Replace($before, $pattern, '<i$1 aria-hidden="true">')

        # Safety: the file must only grow, and only by the attribute we added.
        $expected = $before.Length + ($count * ' aria-hidden="true"'.Length)
        if ($after.Length -ne $expected) {
            throw "Refusing to write $path : expected length $expected but got $($after.Length)"
        }

        [System.IO.File]::WriteAllText($path, $after, $utf8NoBom)
        Write-Host ("  {0,3} fixed  {1}" -f $count, $path.Replace((Get-Location).Path + '\', ''))
        $total += $count
    }
}

Write-Host "`nTotal icons given aria-hidden: $total"
