#!/usr/bin/env bash
# Publish a GitHub release for every version listed in CHANGELOG.md that has
# no tag yet. For each such version the newest commit whose plugin.php
# declares it is tagged v<version>, the plugin zip is built from the tag
# with `git archive` (docs/ and .github/ are export-ignored, see
# .gitattributes), and the release notes are the version's changelog section
# plus install instructions and the zip's checksum.
#
# Runs in GitHub Actions (see .github/workflows/release.yml); needs `gh`,
# a checkout with the full history and GH_TOKEN with contents: write.
set -euo pipefail

repo_url="https://github.com/${GITHUB_REPOSITORY:-tarsus-ai/ilias-ai-trainer}"

git config user.name "github-actions[bot]"
git config user.email "41898282+github-actions[bot]@users.noreply.github.com"

# Versions from the changelog, oldest first, so the newest is created last
# and becomes the latest release.
versions=$(grep -oE '^## \[[0-9]+\.[0-9]+\.[0-9]+\]' CHANGELOG.md | tr -d '#[] ' | tac)
highest=$(echo "$versions" | tail -n 1)

for v in $versions; do
    tag="v$v"
    if git ls-remote --exit-code --tags origin "refs/tags/$tag" >/dev/null 2>&1; then
        echo "$tag exists, skipping"
        continue
    fi

    # The newest commit on this branch whose plugin.php declares the version.
    commit=""
    for c in $(git log --format=%H -- plugin.php); do
        if git show "$c:plugin.php" | grep -Eq "^\\\$version = '$v';"; then
            commit=$c
            break
        fi
    done
    if [ -z "$commit" ]; then
        echo "no commit declares version $v in plugin.php, skipping"
        continue
    fi

    echo "tagging $tag at $commit"
    git tag -a "$tag" -m "Tarsus for ILIAS $v" "$commit"
    git push origin "refs/tags/$tag"

    zip="Tarsus-ilias-plugin-$v.zip"
    git archive --format=zip --prefix=Tarsus/ -o "$zip" "$tag"
    sum=$(sha256sum "$zip" | cut -d ' ' -f 1)

    {
        echo "Tarsus for ILIAS $v – repository object plugin for ILIAS 9, 10 and 11."
        echo
        # The version's section of the changelog, without its heading and
        # without the link references at the end of the file.
        awk -v v="$v" '/^## \[/ { p = ($0 ~ ("^## \\[" v "\\]")); next } p' CHANGELOG.md \
            | grep -vE '^\[[0-9.]+\]: ' || true
        echo
        echo "## Install or update"
        echo
        echo "1. Unzip \`$zip\` into \`public/Customizing/global/plugins/Services/Repository/RepositoryObject/\` (ILIAS 9: without \`public/\`) – it contains the \`Tarsus\` folder."
        echo "2. Run \`composer du\` in the ILIAS root directory."
        echo "3. *Administration › Extending ILIAS › Plugins › Tarsus*: **Install** + **Activate** for a new installation, or **Update** + **Refresh Languages** for an existing one."
        echo
        echo "Full instructions: [README]($repo_url#installation)."
        echo
        echo "## Checksum"
        echo
        echo '```'
        echo "SHA-256  $sum  $zip"
        echo '```'
    } > "notes-$v.md"

    latest=()
    if [ "$v" = "$highest" ]; then
        latest=(--latest)
    fi
    gh release create "$tag" \
        --title "Tarsus for ILIAS $v" \
        --notes-file "notes-$v.md" \
        "${latest[@]}" \
        "$zip#$zip (plugin – unzip into the RepositoryObject slot)"
    echo "released $tag"
done
