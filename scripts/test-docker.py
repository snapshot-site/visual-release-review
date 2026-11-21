"""Exercise the production container with fake keys and no provider calls."""

import argparse
import base64
import http.cookiejar
import json
import os
import re
import secrets
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def docker(*args, check=True, env=None, input=None):
    return subprocess.run(
        ["docker", *args], cwd=ROOT, env=env, input=input, check=check,
        text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=60,
    )


class Container:
    def __init__(self, image, **environment):
        self.name = "visual-review-test-" + secrets.token_hex(5)
        args = [
            "run", "-d", "--name", self.name, "--read-only", "--cap-drop=ALL",
            "--security-opt=no-new-privileges", "--memory=1g", "--cpus=2",
            "--pids-limit=256", "--tmpfs",
            "/app/var:rw,noexec,nosuid,nodev,size=128m,uid=10001,gid=10001,mode=0700",
            "--tmpfs", "/tmp:rw,noexec,nosuid,nodev,size=32m,uid=10001,gid=10001,mode=0700",
            "-p", "127.0.0.1::8080", "-e", "APP_SECRET=" + secrets.token_hex(32),
        ]
        for key, value in environment.items():
            args += ["-e", key + "=" + value]
        try:
            docker(*args, image)
            state = json.loads(docker("inspect", self.name).stdout)[0]
            self.port = state["NetworkSettings"]["Ports"]["8080/tcp"][0]["HostPort"]
        except Exception:
            self.close()
            raise

    def close(self):
        docker("rm", "-f", self.name, check=False)

    def request(self, path="/", data=None, headers=None, opener=None):
        request = urllib.request.Request(
            "http://127.0.0.1:" + self.port + path,
            data=data, headers=headers or {},
        )
        try:
            response = (opener or urllib.request.build_opener()).open(request, timeout=10)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            return response.code, response.headers, response.read().decode(errors="replace")

    def wait(self, expected=200):
        for _ in range(30):
            try:
                if self.request("/healthz")[0] == expected:
                    return
            except (OSError, TimeoutError):
                pass
            time.sleep(1)
        raise AssertionError("Container did not reach the expected readiness state.")


def check_demo(image):
    container = Container(image, TRUSTED_PROXIES="127.0.0.1")
    try:
        container.wait()
        status, headers, body = container.request("/healthz")
        assert status == 200 and body == "ok" and "Set-Cookie" not in headers
        opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar())
        )
        status, headers, body = container.request("/?lang=en", opener=opener)
        assert status == 200 and headers["Content-Language"] == "en"
        assert "One small change." in body
        token = re.search(r'name="_token" value="([^"]+)"', body).group(1)
        status, _, body = container.request(
            "/demo/broken", urllib.parse.urlencode({"_token": token}).encode(), opener=opener
        )
        assert status == 200 and "Contradicted" in body
        for path, expected in [
            ("/missing?lang=en", 404), ("/review?lang=en", 405), ("/.env", 404),
            ("/.git/config", 404), ("/composer.json", 404), ("/router.php", 404),
            ("/index.php", 404), ("/health.php", 404),
        ]:
            assert container.request(path)[0] == expected, path
        assert container.request("/", headers={"Host": "untrusted.example"})[0] == 400
        assert container.request("/review", b"_token=invalid")[0] == 403
        _, headers, _ = container.request("/", headers={"X-Forwarded-Proto": "https"})
        assert "; secure" not in headers.get("Set-Cookie", "").lower()
        trusted = docker(
            "exec", container.name, "curl", "--silent", "--dump-header", "-",
            "--output", "/dev/null", "--header", "X-Forwarded-Proto: https",
            "http://127.0.0.1:8080/",
        ).stdout.lower()
        assert "; secure" in trusted
        checks = r'''
assert(getenv('APP_ENV') === 'prod' && getenv('APP_DEBUG') === '0');
assert(posix_geteuid() !== 0);
foreach (['.env.local', '.env.docker.local', '.git', 'docs', 'vendor/bin/phpunit', 'node_modules/@playwright/test', 'public/router.php'] as $path) {
    assert(!file_exists('/app/'.$path));
}
assert(!is_writable('/app/src/Kernel.php'));
assert(is_writable('/app/var/sessions'));
assert(file_exists('/app/node_modules/@snapshot-site/mcp/build/server.js'));
'''
        docker("exec", container.name, "php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "-r", checks)
        state = json.loads(docker("inspect", container.name).stdout)[0]["HostConfig"]
        assert state["ReadonlyRootfs"] and state["CapDrop"] == ["ALL"]
        assert "no-new-privileges" in " ".join(state["SecurityOpt"])
        print("PASS: read-only non-root runtime, PHP readiness, replay, hidden files, host checks and secure proxy cookies", flush=True)
    finally:
        container.close()


def check_live(image):
    password = secrets.token_hex(20)
    hashed = docker("run", "--rm", "--entrypoint", "php", image, "-r", "echo password_hash($argv[1], PASSWORD_BCRYPT);", password).stdout
    container = Container(image, OPENAI_API_KEY="fake-no-api-call", SNAPSHOT_SITE_API_KEY="fake-no-api-call",
                          REVIEW_BASIC_USER="reviewer", REVIEW_BASIC_PASSWORD_HASH=hashed)
    try:
        container.wait()
        for path in ["/", "/review", "/%72eview", "/index.php/review", "/healthz/../review"]:
            assert container.request(path)[0] in [401, 404], path
        assert container.request("/review", b"_token=invalid")[0] == 401
        auth = {"Authorization": "Basic " + base64.b64encode(("reviewer:" + password).encode()).decode()}
        assert container.request("/?lang=en", headers=auth)[0] == 200
        assert container.request("/review", b"_token=invalid", headers=auth)[0] == 403
        mcp = r'''<?php
require '/app/vendor/autoload.php';
$client = Mcp\Client\Client::builder()->setClientInfo('docker-test', '1.0.0')->setInitTimeout(10)->setRequestTimeout(10)->setMaxRetries(0)->build();
try {
    $client->connect(new Mcp\Client\Transport\StdioTransport('node', ['/app/node_modules/@snapshot-site/mcp/build/server.js'], '/app', ['PATH' => getenv('PATH'), 'SNAPSHOT_SITE_API_KEY' => 'fake-no-api-call']));
    $names = array_map(static fn ($tool) => $tool->name, $client->listTools()->tools);
    if (!in_array('compare', $names, true)) { exit(1); }
} finally { $client->disconnect(); }
'''
        docker("exec", "-i", container.name, "php", input=mcp)
        env = dict(os.environ, APP_SECRET=secrets.token_hex(32), OPENAI_API_KEY="", SNAPSHOT_SITE_API_KEY="",
                   REVIEW_BASIC_USER="reviewer", REVIEW_BASIC_PASSWORD_HASH=hashed, SMOKE_PASSWORD=password, REVIEW_IMAGE=image)
        docker("compose", "--env-file", ".env.docker.example", "run", "--rm", "--no-deps", "-e", "SMOKE_PASSWORD",
               "--entrypoint", "php", "app", "-r", 'exit(password_verify(getenv("SMOKE_PASSWORD"), getenv("REVIEW_BASIC_PASSWORD_HASH")) ? 0 : 1);', env=env)
        print("PASS: live authentication, CSRF, Compose hash handling and actual PHP-to-Node MCP handshake (no provider calls)", flush=True)
    finally:
        container.close()


def check_failures(image):
    for values, message in [
        ({}, "APP_SECRET"),
        ({"APP_SECRET": secrets.token_hex(32), "APP_DEBUG": "1"}, "APP_DEBUG"),
        ({"APP_SECRET": secrets.token_hex(32), "OPENAI_API_KEY": "fake"}, "both provider"),
        ({"APP_SECRET": secrets.token_hex(32), "OPENAI_API_KEY": "fake", "SNAPSHOT_SITE_API_KEY": "fake"}, "REVIEW_BASIC_USER"),
    ]:
        args = ["run", "--rm"]
        for key, value in values.items():
            args += ["-e", key + "=" + value]
        result = docker(*args, image, check=False)
        assert result.returncode != 0 and message in result.stderr, message
    container = Container(image, TRUSTED_HOSTS="^untrusted-health.example$")
    try:
        container.wait(expected=503)
        assert container.request("/healthz")[2] == "unavailable"
    finally:
        container.close()
    print("PASS: fail-closed startup and readiness failure when the Symfony subrequest cannot succeed", flush=True)


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--image", default="visual-release-review:local")
    args = parser.parse_args()
    check_demo(args.image)
    check_live(args.image)
    check_failures(args.image)
