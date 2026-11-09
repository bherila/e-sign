#!/usr/bin/env python3
"""HTTP driver for the Compose end-to-end smoke test (issue #37). See scripts/e2e/compose-smoke.sh.

Plays the two outside parties the stack serves, over real HTTP and nothing else:

* the consumer, through the Firma-compatible facade with its API key (`send`, `download`);
* the guest signer, through the signing pages as a browser does (`sign`): landing GET, Continue
  POST, values POST, accept POST, carrying the session cookies and the CSRF token forward.

Standard library only. Cookies are kept by hand rather than by `http.cookiejar` because every
cookie the production build issues is `Secure`, and this job talks to the container over plain
HTTP on loopback, where a browser treats the origin as secure and the standard jar does not.
"""

from __future__ import annotations

import argparse
import base64
import hashlib
import html
import json
import re
import struct
import sys
import urllib.error
import urllib.parse
import urllib.request
import zlib

FACADE = "/functions/v1/signing-request-api"


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):  # noqa: D401 - urllib hook
        return None


OPENER = urllib.request.build_opener(NoRedirect, urllib.request.ProxyHandler({}))


class Browser:
    def __init__(self, base: str) -> None:
        self.base = base.rstrip("/")
        self.cookies: dict[str, str] = {}

    def request(self, method: str, url: str, body: bytes | None = None, headers: dict[str, str] | None = None):
        target = url if url.startswith("http") else self.base + url
        req = urllib.request.Request(target, data=body, method=method, headers=dict(headers or {}))
        if self.cookies:
            req.add_header("Cookie", "; ".join(f"{k}={v}" for k, v in self.cookies.items()))
        try:
            response = OPENER.open(req, timeout=60)
        except urllib.error.HTTPError as error:  # 3xx/4xx/5xx all arrive here without redirects
            response = error
        for header in response.headers.get_all("Set-Cookie") or []:
            name, _, rest = header.partition("=")
            self.cookies[name.strip()] = rest.split(";", 1)[0]
        return response.status, dict(response.headers), response.read()

    def follow(self, method: str, url: str, body: bytes | None = None, headers: dict[str, str] | None = None):
        status, head, payload = self.request(method, url, body, headers)
        hops = 0
        while status in (301, 302, 303, 307, 308) and hops < 5:
            url = head.get("Location") or head.get("location")
            status, head, payload = self.request("GET", url)
            hops += 1
        return status, head, payload


def fail(message: str, payload: bytes | None = None) -> None:
    print("drive.py: " + message, file=sys.stderr)
    if payload:
        print(payload[:2000].decode("utf-8", "replace"), file=sys.stderr)
    sys.exit(1)


def png_data_url(width: int = 300, height: int = 100) -> str:
    """A transparent PNG with one dark horizontal stroke: a synthetic drawn signature."""
    rows = b""
    for y in range(height):
        pixel = b"\x11\x11\x11\xff" if y == height // 2 else b"\x00\x00\x00\x00"
        rows += b"\x00" + pixel * width

    def chunk(kind: bytes, data: bytes) -> bytes:
        return struct.pack(">I", len(data)) + kind + data + struct.pack(">I", zlib.crc32(kind + data) & 0xFFFFFFFF)

    png = b"\x89PNG\r\n\x1a\n" + chunk(b"IHDR", struct.pack(">IIBBBBB", width, height, 8, 6, 0, 0, 0))
    png += chunk(b"IDAT", zlib.compress(rows)) + chunk(b"IEND", b"")
    return "data:image/png;base64," + base64.b64encode(png).decode()


def api(base: str, key: str, method: str, path: str, body: dict | None = None):
    browser = Browser(base)
    data = None if body is None else json.dumps(body).encode()
    headers = {"Authorization": key, "Accept": "application/json"}
    if data is not None:
        headers["Content-Type"] = "application/json"
    return browser.request(method, FACADE + path, data, headers)


def send(args) -> None:
    with open(args.pdf, "rb") as handle:
        document = base64.b64encode(handle.read()).decode()

    status, _, payload = api(args.base, args.key, "POST", "/signing-requests/create-and-send", {
        "name": "Synthetic Compose end-to-end agreement",
        "document": document,
        "recipients": [{
            "first_name": "Dana", "last_name": "Signer", "email": args.email,
            "designation": "Signer", "order": 1,
        }],
        "fields": [{
            "type": "signature", "page_number": 1, "variable_name": "Signer Signature",
            "position": {"x": 10.0, "y": 70.0, "width": 30.0, "height": 5.0},
        }],
    })
    if status != 201:
        fail(f"create-and-send answered {status}", payload)
    print(json.loads(payload)["id"])


def sign(args) -> None:
    browser = Browser(args.base)
    landing = urllib.parse.urlparse(args.url).path

    status, _, page = browser.follow("GET", landing)
    if status != 200:
        fail(f"the invitation landing page answered {status}", page)
    text = page.decode("utf-8", "replace")
    action = re.search(r'<form[^>]+action="([^"]*?/start)"', text)
    if not action:
        fail("the landing page has no Continue form", page)
    if 'name="code"' in text:
        fail("the landing page asks for a mailbox code; this job signs without one", page)
    # Every hidden input of the form, the CSRF token among them, as a browser submits them.
    hidden = {
        html.unescape(name): html.unescape(value)
        for name, value in re.findall(r'<input[^>]*type="hidden"[^>]*name="([^"]+)"[^>]*value="([^"]*)"', text)
    }
    if "_token" not in hidden:
        fail("the landing form carries no CSRF token", page)

    status, head, page = browser.request(
        "POST", html.unescape(action.group(1)),
        urllib.parse.urlencode(hidden).encode(),
        {"Content-Type": "application/x-www-form-urlencoded"},
    )
    if status not in (302, 303):
        fail(f"Continue answered {status}", page)

    status, _, page = browser.follow("GET", head.get("Location") or head.get("location"))
    if status != 200:
        fail(f"the signing session page answered {status}", page)
    found = re.search(r'data-signing="([^"]+)"', page.decode("utf-8", "replace"))
    if not found:
        fail("the signing page carries no payload", page)
    payload = json.loads(html.unescape(found.group(1)))

    signatures = payload["required_signature_field_ids"]
    values = {field_id: png_data_url() for field_id in signatures}
    csrf = payload["csrf_token"]

    status, _, saved = browser.request(
        "POST", payload["urls"]["values"], json.dumps({"values": values}).encode(),
        {"Content-Type": "application/json", "Accept": "application/json", "X-CSRF-TOKEN": csrf,
         "X-Requested-With": "XMLHttpRequest"},
    )
    if status != 200:
        fail(f"saving the values answered {status}", saved)
    reviewed = json.loads(saved)["reviewed"]

    form = [
        ("_token", csrf), ("consent_accepted", "1"), ("intent_confirmed", "1"),
        ("consent_version", payload["consent"]["recorded_version"]),
        ("reviewed_material_sha256", reviewed["material_values_sha256"]),
        ("reviewed_envelope_version", str(reviewed["envelope_version"])),
    ] + [("signature_field_ids[]", field_id) for field_id in signatures]

    status, _, page = browser.request(
        "POST", payload["urls"]["accept"], urllib.parse.urlencode(form).encode(),
        {"Content-Type": "application/x-www-form-urlencoded"},
    )
    if status not in (302, 303):
        fail(f"accepting answered {status}", page)


def download(args) -> None:
    status, _, body = api(args.base, args.key, "GET", f"/signing-requests/{args.envelope}/download")
    if status != 200:
        fail(f"/download answered {status}", body)
    described = json.loads(body)
    if described.get("status") != "finished" or described.get("is_partial") is not False:
        fail("the download is not the finished agreement", body)

    # The link is a capability: no API key, as a browser follows it.
    status, _, pdf = Browser(args.base).request("GET", described["download_url"])
    if status != 200 or not pdf.startswith(b"%PDF-"):
        fail(f"the download link answered {status} without a PDF", pdf)
    digest = hashlib.sha256(pdf).hexdigest()
    if digest != args.sha256:
        fail(f"the downloaded PDF hashes to {digest}, and the published artifact to {args.sha256}")
    print(f"sealed PDF: {len(pdf)} bytes, sha256 {digest}")


def main() -> None:
    parser = argparse.ArgumentParser()
    sub = parser.add_subparsers(dest="command", required=True)

    p = sub.add_parser("send")
    p.add_argument("--base", required=True)
    p.add_argument("--key", required=True)
    p.add_argument("--pdf", required=True)
    p.add_argument("--email", required=True)
    p.set_defaults(run=send)

    p = sub.add_parser("sign")
    p.add_argument("--base", required=True)
    p.add_argument("--url", required=True)
    p.set_defaults(run=sign)

    p = sub.add_parser("download")
    p.add_argument("--base", required=True)
    p.add_argument("--key", required=True)
    p.add_argument("--envelope", required=True)
    p.add_argument("--sha256", required=True)
    p.set_defaults(run=download)

    args = parser.parse_args()
    args.run(args)


if __name__ == "__main__":
    main()
