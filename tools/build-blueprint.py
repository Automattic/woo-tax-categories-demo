#!/usr/bin/env python3
"""Build playground/blueprint.json or blueprint-eu.json: WooCommerce + plugin files + a test store.

Usage:
    python3 tools/build-blueprint.py                 # US store (default), writes blueprint.json
    python3 tools/build-blueprint.py --store us      # same
    python3 tools/build-blueprint.py --store eu      # EU/UK/AU demo, writes blueprint-eu.json
    python3 tools/build-blueprint.py --store all     # build both

The plugin files, the three in-store test pages, the acceptance helper and the SQLite shim are
shared across stores; only the setup script and (for EU) one extra check page differ.
"""
import argparse, json, pathlib, urllib.parse

STORES = {
    # "eu" is the primary store the README advertises, so its output is just blueprint.json.
    # The US-centric store (state exceptions + 111-case suite) ships as blueprint-us.json for
    # developers; the README doesn't mention it, but Spacefast still serves it.
    "eu": {
        "setup": "playground/setup-eu.php",
        "output": "playground/blueprint.json",
        "extra_files": [("playground/wctc-eu-check.php", "/wordpress/wctc-eu-check.php")],
    },
    "us": {
        "setup": "playground/setup.php",
        "output": "playground/blueprint-us.json",
        "extra_files": [],  # all shared test pages are already included below
    },
}


def build(root: pathlib.Path, store: str) -> pathlib.Path:
    cfg = STORES[store]
    plugin_files = (
        ["woo-core-tax-categories.php"]
        + sorted(str(p.relative_to(root)) for p in (root / "src").rglob("*.php"))
        + sorted(str(p.relative_to(root)) for p in (root / "assets").glob("*.*"))
    )
    dest = "/wordpress/wp-content/plugins/woo-core-tax-categories/"

    steps = [
        {"step": "installPlugin", "pluginData": {"resource": "wordpress.org/plugins", "slug": "woocommerce"}, "options": {"activate": True}},
        {"step": "mkdir", "path": dest + "src/Admin"},
        {"step": "mkdir", "path": dest + "assets"},
    ]
    for f in plugin_files:
        steps.append({"step": "writeFile", "path": dest + f, "data": (root / f).read_text()})
    steps += [
        {"step": "activatePlugin", "pluginPath": "woo-core-tax-categories/woo-core-tax-categories.php"},
        {"step": "runPHP", "code": (root / cfg["setup"]).read_text()},
        {"step": "writeFile", "path": "/wordpress/wctc-test.php", "data": (root / "playground/wctc-test.php").read_text()},
        {"step": "writeFile", "path": "/wordpress/wctc-stress.php", "data": (root / "playground/wctc-stress.php").read_text()},
        {"step": "writeFile", "path": "/wordpress/wctc-suite.php", "data": (root / "playground/wctc-suite.php").read_text()},
        {"step": "writeFile", "path": "/wordpress/wctc-acceptance.php", "data": (root / "playground/wctc-acceptance.php").read_text()},
    ]
    for src, dst in cfg["extra_files"]:
        steps.append({"step": "writeFile", "path": dst, "data": (root / src).read_text()})
    steps += [
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
    out = root / cfg["output"]
    out.write_text(json.dumps(blueprint, indent=1))
    url = "https://playground.wordpress.net/#" + urllib.parse.quote(json.dumps(blueprint, separators=(",", ":")))
    (root / cfg["output"].replace(".json", "-url.txt")).write_text(url + "\n")
    print(f"{out}  {len(url)} chars in inline URL")
    return out


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--store", choices=["eu", "us", "all"], default="eu", help="Which store to build (default: eu — the primary blueprint.json)")
    args = ap.parse_args()
    root = pathlib.Path(__file__).resolve().parent.parent
    for store in (["eu", "us"] if args.store == "all" else [args.store]):
        build(root, store)


if __name__ == "__main__":
    main()
