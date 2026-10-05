#!/usr/bin/env python3
"""Build playground/blueprint.json: WooCommerce + this plugin (inlined) + a test store + the test runner."""
import json, pathlib, subprocess, urllib.parse

root = pathlib.Path(__file__).resolve().parent.parent
files = ["woo-core-tax-categories.php"] + sorted(str(p.relative_to(root)) for p in (root / "src").rglob("*.php")) + sorted(str(p.relative_to(root)) for p in (root / "assets").glob("*.*"))
dest = "/wordpress/wp-content/plugins/woo-core-tax-categories/"

steps = [
    {"step": "installPlugin", "pluginData": {"resource": "wordpress.org/plugins", "slug": "woocommerce"}, "options": {"activate": True}},
    {"step": "mkdir", "path": dest + "src/Admin"},
    {"step": "mkdir", "path": dest + "assets"},
]
for f in files:
    steps.append({"step": "writeFile", "path": dest + f, "data": (root / f).read_text()})
steps += [
    {"step": "activatePlugin", "pluginPath": "woo-core-tax-categories/woo-core-tax-categories.php"},
    {"step": "runPHP", "code": (root / "playground/setup.php").read_text()},
    {"step": "writeFile", "path": "/wordpress/wctc-test.php", "data": (root / "playground/wctc-test.php").read_text()},
    {"step": "writeFile", "path": "/wordpress/wctc-stress.php", "data": (root / "playground/wctc-stress.php").read_text()},
    {"step": "writeFile", "path": "/wordpress/wctc-suite.php", "data": (root / "playground/wctc-suite.php").read_text()},
    {"step": "writeFile", "path": "/wordpress/wctc-acceptance.php", "data": (root / "playground/wctc-acceptance.php").read_text()},
    {"step": "mkdir", "path": "/wordpress/wp-content/mu-plugins"},
    {"step": "writeFile", "path": "/wordpress/wp-content/mu-plugins/wctc-sqlite-shim.php", "data": (root / "playground/sqlite-shim.php").read_text()},
]
blueprint = {
    "$schema": "https://playground.wordpress.net/blueprint-schema.json",
    "landingPage": "/wp-admin/admin.php?page=wc-settings&tab=tax&section=tax_categories",
    "preferredVersions": {"php": "8.3", "wp": "latest"},
    "features": {"networking": True},
    "login": True,
    "steps": steps,
}
out = root / "playground/blueprint.json"
out.write_text(json.dumps(blueprint, indent=1))
url = "https://playground.wordpress.net/#" + urllib.parse.quote(json.dumps(blueprint, separators=(",", ":")))
(root / "playground/playground-url.txt").write_text(url + "\n")
print(out, len(url), "chars in URL")
