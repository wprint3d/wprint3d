#!/usr/bin/env bash

set -euo pipefail

archive=''
expected_id=''
expected_version=''
expected_sha256=''
destination=''
inventory=''
compatibility=''

usage() {
    cat >&2 <<'EOF'
Usage: scripts/stage-builtin-plugin.sh \
  --archive <signed.w3dp> \
  --expected-plugin-id <id> \
  --expected-version <semver> \
  --expected-sha256 <sha256> \
  --destination resources/plugins/builtin/archives/<id>-<version>.w3dp \
  [--inventory resources/plugins/builtin/index.json] \
  [--compatibility-record release/compatibility.json]
EOF
    exit 2
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --archive) archive="${2:-}"; shift 2 ;;
        --expected-plugin-id) expected_id="${2:-}"; shift 2 ;;
        --expected-version) expected_version="${2:-}"; shift 2 ;;
        --expected-sha256) expected_sha256="${2:-}"; shift 2 ;;
        --destination) destination="${2:-}"; shift 2 ;;
        --inventory) inventory="${2:-}"; shift 2 ;;
        --compatibility-record) compatibility="${2:-}"; shift 2 ;;
        *) usage ;;
    esac
done

[[ -n "$archive" && -n "$expected_id" && -n "$expected_version" && -n "$expected_sha256" && -n "$destination" ]] || usage
[[ -f "$archive" ]] || { echo "Archive not found: $archive" >&2; exit 1; }
[[ "$expected_id" =~ ^[a-z0-9][a-z0-9._-]*$ ]] || { echo 'Invalid plugin id.' >&2; exit 1; }
[[ "$expected_version" =~ ^[0-9]+\.[0-9]+\.[0-9]+([+-][0-9A-Za-z.-]+)?$ ]] || { echo 'Invalid plugin version.' >&2; exit 1; }
[[ "$expected_sha256" =~ ^[a-fA-F0-9]{64}$ ]] || { echo 'Invalid expected SHA-256.' >&2; exit 1; }
[[ "$destination" != /* && "$destination" != *'..'* ]] || { echo 'Destination must be a relative path without traversal.' >&2; exit 1; }

actual_sha256="$(sha256sum "$archive" | awk '{print tolower($1)}')"
[[ "$actual_sha256" == "${expected_sha256,,}" ]] || {
    echo "Archive checksum mismatch: expected $expected_sha256, got $actual_sha256" >&2
    exit 1
}

command -v unzip >/dev/null 2>&1 || { echo 'unzip is required.' >&2; exit 1; }
command -v php >/dev/null 2>&1 || { echo 'PHP is required for WPrint package verification.' >&2; exit 1; }
[[ -f artisan && -f vendor/autoload.php ]] || {
    echo 'Run this script from a WPrint checkout with Composer dependencies installed.' >&2
    exit 1
}

# The host verifier checks the embedded signature against this WPrint
# instance's trusted signer set before the archive can enter the image.
php artisan plugin:verify "$archive" --require-trusted >/dev/null

temporary_root="$(mktemp -d)"
inventory_path="${inventory:-$(dirname "$destination")/../index.json}"
temporary_archive="${destination}.part.$$"
inventory_part="${inventory_path}.part.$$"
archive_backup="${destination}.previous.$$"
inventory_backup="${inventory_path}.previous.$$"
committed=0
rollback() {
    if [[ "$committed" -eq 1 ]]; then
        rm -rf "$temporary_root"
        return
    fi
    rm -f "$temporary_archive" "$inventory_part"
    if [[ -f "$archive_backup" ]]; then
        rm -f "$destination"
        mv -f "$archive_backup" "$destination"
    fi
    if [[ -f "$inventory_backup" ]]; then
        rm -f "$inventory_path"
        mv -f "$inventory_backup" "$inventory_path"
    fi
    rm -rf "$temporary_root"
}
trap rollback EXIT
unzip -p "$archive" plugin.json > "$temporary_root/plugin.json"

export STAGE_PLUGIN_ID="$expected_id"
export STAGE_PLUGIN_VERSION="$expected_version"
export STAGE_ARCHIVE_SHA256="$actual_sha256"
export STAGE_ARCHIVE_PATH="$destination"
export STAGE_INVENTORY_PATH="$inventory_path"
export STAGE_INVENTORY_PART="$inventory_part"
export STAGE_COMPATIBILITY_PATH="$compatibility"
export TMP_PLUGIN_MANIFEST="$temporary_root/plugin.json"
mkdir -p "$(dirname "$destination")"
mkdir -p "$(dirname "$inventory_path")"
cp "$archive" "$temporary_archive"
printf '%s  %s\n' "$actual_sha256" "$temporary_archive" | sha256sum --check --status

php -r '
$manifest = json_decode(file_get_contents(getenv("TMP_PLUGIN_MANIFEST")), true, 32, JSON_THROW_ON_ERROR);
$id = getenv("STAGE_PLUGIN_ID");
$version = getenv("STAGE_PLUGIN_VERSION");
if (($manifest["id"] ?? null) !== $id || ($manifest["version"] ?? null) !== $version) {
    fwrite(STDERR, "Archive manifest does not match the expected built-in identity.\n");
    exit(1);
}
$signature = $manifest["signature"] ?? [];
if (($signature["algorithm"] ?? "none") === "none") {
    fwrite(STDERR, "Built-in package must be signed.\n");
    exit(1);
}
$manifestFingerprint = strtolower((string)($signature["publicKeySha256"] ?? ""));
if (!preg_match("~^[a-f0-9]{64}$~", $manifestFingerprint)) {
    throw new RuntimeException("Built-in package signature has no valid public-key fingerprint.");
}
$inventoryPath = getenv("STAGE_INVENTORY_PATH");
$destination = getenv("STAGE_ARCHIVE_PATH");
$compatibilityPath = getenv("STAGE_COMPATIBILITY_PATH");
$compatibility = [];
if ($compatibilityPath !== "") {
    if (!is_file($compatibilityPath)) throw new RuntimeException("Compatibility record is missing.");
    $compatibility = json_decode(file_get_contents($compatibilityPath), true, 32, JSON_THROW_ON_ERROR);
    if (($compatibility["schemaVersion"] ?? null) !== 1) {
        throw new RuntimeException("Compatibility record has an unsupported schema.");
    }
    if (($compatibility["plugin"]["id"] ?? null) !== $id
        || ($compatibility["plugin"]["version"] ?? null) !== $version
        || (int)($compatibility["plugin"]["sdkVersion"] ?? 0) !== (int)($manifest["sdkVersion"] ?? 0)
        || (int)($compatibility["plugin"]["sdkRevision"] ?? 0) !== (int)($manifest["sdkRevision"] ?? 0)) {
        throw new RuntimeException("Compatibility record does not match the plugin manifest.");
    }
    if ((string)($compatibility["w3dp"]["sha256"] ?? "") !== (string)getenv("STAGE_ARCHIVE_SHA256")) {
        throw new RuntimeException("Compatibility record does not match the W3DP checksum.");
    }
    if (strtolower((string)($compatibility["w3dp"]["signerFingerprint"] ?? "")) !== $manifestFingerprint) {
        throw new RuntimeException("Compatibility record signer fingerprint does not match the signed manifest.");
    }
    if (trim((string)($compatibility["minimumWPrintCoreVersion"] ?? "")) === "") {
        throw new RuntimeException("Compatibility record is missing minimumWPrintCoreVersion.");
    }
    if (($compatibility["w3dp"]["fileName"] ?? "") !== ""
        && $compatibility["w3dp"]["fileName"] !== basename($destination)) {
        throw new RuntimeException("Compatibility record does not match the archive filename.");
    }
    $runtimeImage = $compatibility["runtimeImage"] ?? null;
    $gateway = $compatibility["gateway"] ?? null;
    if (!is_array($runtimeImage) || !is_array($gateway)) {
        throw new RuntimeException("Compatibility record is missing canonical runtime image metadata.");
    }
    $recordedImage = (string)($compatibility["gateway"]["image"] ?? "");
    $canonicalImage = (string)($runtimeImage["reference"] ?? "");
    if ($canonicalImage === "" || $recordedImage === "" || $canonicalImage !== $recordedImage) {
        throw new RuntimeException("Compatibility record has inconsistent runtime image references.");
    }
    if ($recordedImage !== (string)($manifest["images"][0]["image"] ?? "")) {
        throw new RuntimeException("Compatibility record does not match the runtime image.");
    }
    foreach (["runtimeImage", "gateway"] as $imageKey) {
        $platforms = $compatibility[$imageKey]["platforms"] ?? null;
        if (!is_array($platforms) || !in_array("linux/amd64", $platforms, true) || !in_array("linux/arm64", $platforms, true)) {
            throw new RuntimeException("Compatibility record must declare linux/amd64 and linux/arm64.");
        }
    }
}
$record = [
    "id" => $id,
    "version" => $version,
    "archive" => basename(dirname($destination)) === "archives"
        ? "archives/" . basename($destination)
        : basename($destination),
    "sha256" => getenv("STAGE_ARCHIVE_SHA256"),
    "defaultEnabled" => false,
    "required" => false,
    "feature" => $id === "cura-web-ui" ? "builtin_cura_enabled" : null,
    "compatibility" => $compatibility ?: null,
];
$inventory = ["schemaVersion" => 1, "plugins" => []];
if (is_file($inventoryPath)) {
    $inventory = json_decode(file_get_contents($inventoryPath), true, 32, JSON_THROW_ON_ERROR);
    if (($inventory["schemaVersion"] ?? null) !== 1 || !is_array($inventory["plugins"] ?? null)) {
        throw new RuntimeException("Existing built-in inventory has an unsupported schema.");
    }
}
$inventory["plugins"] = array_values(array_filter($inventory["plugins"], fn ($entry) => ($entry["id"] ?? null) !== $id));
$inventory["plugins"][] = array_filter($record, fn ($value) => $value !== null);
usort($inventory["plugins"], fn ($a, $b) => strcmp((string)($a["id"] ?? ""), (string)($b["id"] ?? "")));
if (!is_dir(dirname($inventoryPath))) mkdir(dirname($inventoryPath), 0775, true);
$inventoryPart = getenv("STAGE_INVENTORY_PART") ?: $inventoryPath . ".part";
file_put_contents($inventoryPart, json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
'

if [[ -e "$destination" ]]; then
    mv -f "$destination" "$archive_backup"
fi
if [[ -e "$inventory_path" ]]; then
    mv -f "$inventory_path" "$inventory_backup"
fi
mv -f "$temporary_archive" "$destination"
mv -f "$inventory_part" "$inventory_path"
committed=1
rm -f "$archive_backup" "$inventory_backup"
echo "Staged signed built-in $expected_id $expected_version at $destination"
