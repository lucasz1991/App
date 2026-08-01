import base64
import io
import json
import os
import shutil
import stat
import subprocess
import sys
import tempfile
import threading
import time
import unittest
import wave
from http.client import HTTPConnection
from pathlib import Path
from types import SimpleNamespace
from unittest import mock


SERVICE_ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(SERVICE_ROOT))
sys.path.insert(0, str(SERVICE_ROOT / "deploy"))

import speech_service as service
import provision_client_token as token_provisioner


TEST_TOKEN = "unit-test-token-that-is-not-a-production-secret"


def wav_bytes(seconds=0.05, frame_rate=16000):
    buffer = io.BytesIO()
    with wave.open(buffer, "wb") as wav_file:
        wav_file.setnchannels(1)
        wav_file.setsampwidth(2)
        wav_file.setframerate(frame_rate)
        wav_file.writeframes(b"\x00\x00" * max(1, int(seconds * frame_rate)))
    return buffer.getvalue()


def config_mapping(temp_dir, **overrides):
    limits = {
        "max_body_bytes": 100000,
        "max_audio_bytes": 70000,
        "max_audio_seconds": 60,
        "max_text_chars": 100,
        "max_transcript_chars": 100,
        "max_output_audio_bytes": 100000,
        "request_read_timeout_seconds": 2,
    }
    limits.update(overrides.pop("limits", {}))
    concurrency = {"whisper": 1, "piper": 1, "http_threads": 8, "queue_wait_seconds": 0}
    concurrency.update(overrides.pop("concurrency", {}))
    raw = {
        "server": {"port": overrides.pop("port", 8092)},
        "clients": {
            "railtime": {"token_sha256": service.token_sha256(TEST_TOKEN)},
            "followflow": {"token_sha256": service.token_sha256("second-test-token")},
        },
        "engines": {
            "ffmpeg": {"command": [sys.executable], "timeout_seconds": 60},
            "whisper": {
                "command": [sys.executable],
                "model": str(Path(temp_dir) / "whisper.bin"),
                "language": "de",
                "threads": 2,
                "timeout_seconds": 240,
            },
            "piper": {
                "command": [sys.executable],
                "model": str(Path(temp_dir) / "voice.onnx"),
                "config": str(Path(temp_dir) / "voice.onnx.json"),
                "mode": "cli",
                "timeout_seconds": 120,
            },
        },
        "limits": limits,
        "concurrency": concurrency,
        "runtime": {"temp_dir": str(Path(temp_dir) / "runtime")},
    }
    raw.update(overrides)
    return raw


def make_config(temp_dir, **overrides):
    return service.ServiceConfig.from_mapping(config_mapping(temp_dir, **overrides))


class FakeEngines:
    def __init__(self):
        self.transcription = "Hallo aus dem Test"
        self.audio = wav_bytes()
        self.transcription_calls = []
        self.synthesis_calls = []

    def readiness(self):
        return {"ffmpeg": "ready", "whisper": "ready", "piper": "ready"}

    def transcribe(self, audio, extension, language, max_audio_seconds):
        self.transcription_calls.append((audio, extension, language, max_audio_seconds))
        return self.transcription

    def synthesize(self, text, speed):
        self.synthesis_calls.append((text, speed))
        return self.audio


class HttpApiTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.config = make_config(self.temporary.name)
        self.engines = FakeEngines()
        self.app = service.SpeechApplication(self.config, self.engines)
        self.server = service.build_server(self.app, port=0)
        self.thread = threading.Thread(
            target=self.server.serve_forever,
            kwargs={"poll_interval": 0.01},
            daemon=True,
        )
        self.thread.start()

    def tearDown(self):
        self.server.shutdown()
        self.server.server_close()
        self.thread.join(timeout=2)
        self.temporary.cleanup()

    def request(self, method, path, payload=None, authenticated=False, headers=None):
        outgoing_headers = dict(headers or {})
        body = None
        if payload is not None:
            body = json.dumps(payload).encode("utf-8")
            outgoing_headers.setdefault("Content-Type", "application/json")
        if authenticated:
            outgoing_headers.setdefault("X-Client-ID", "railtime")
            outgoing_headers.setdefault("Authorization", "Bearer " + TEST_TOKEN)
        connection = HTTPConnection("127.0.0.1", self.server.server_port, timeout=3)
        connection.request(method, path, body=body, headers=outgoing_headers)
        response = connection.getresponse()
        response_body = response.read()
        response_headers = dict(response.getheaders())
        connection.close()
        return response.status, response_headers, response_body

    def request_json(self, *args, **kwargs):
        status, headers, body = self.request(*args, **kwargs)
        return status, headers, json.loads(body.decode("utf-8"))

    def test_health_is_unauthenticated_and_discloses_no_paths(self):
        status, headers, payload = self.request_json("GET", "/healthz")
        self.assertEqual(200, status)
        self.assertEqual({"status": "ok"}, payload)
        self.assertNotIn(self.temporary.name, json.dumps(payload))
        self.assertTrue(headers["X-Request-ID"])

    def test_status_requires_authentication(self):
        status, _, payload = self.request_json("GET", "/v1/status")
        self.assertEqual(401, status)
        self.assertEqual("unauthorized", payload["error"]["code"])

    def test_status_reports_readiness_limits_and_no_paths(self):
        status, _, payload = self.request_json("GET", "/v1/status", authenticated=True)
        self.assertEqual(200, status)
        self.assertEqual("ready", payload["status"])
        self.assertEqual("ready", payload["engines"]["whisper"])
        self.assertEqual(70000, payload["limits"]["max_audio_bytes"])
        self.assertEqual(8, payload["concurrency"]["http_threads"])
        self.assertNotIn(self.temporary.name, json.dumps(payload))

    def test_unknown_client_and_wrong_token_share_the_same_public_error(self):
        first = self.request_json(
            "GET",
            "/v1/status",
            headers={"X-Client-ID": "missing", "Authorization": "Bearer wrong"},
        )[2]
        second = self.request_json(
            "GET",
            "/v1/status",
            headers={"X-Client-ID": "railtime", "Authorization": "Bearer wrong"},
        )[2]
        self.assertEqual(first["error"], second["error"])

    def test_transcription_contract_and_request_id(self):
        payload = {
            "audio_base64": base64.b64encode(b"fake-webm-audio").decode("ascii"),
            "filename": "recording.webm",
            "mime_type": "audio/webm;codecs=opus",
            "language": "de-DE",
        }
        status, headers, response = self.request_json(
            "POST",
            "/v1/transcriptions",
            payload,
            authenticated=True,
            headers={"X-Request-ID": "railtime-123"},
        )
        self.assertEqual(200, status)
        self.assertEqual(
            {"text": "Hallo aus dem Test", "request_id": "railtime-123"},
            response,
        )
        self.assertEqual("railtime-123", headers["X-Request-ID"])
        self.assertEqual(
            (b"fake-webm-audio", ".webm", "de-DE", 60.0),
            self.engines.transcription_calls[0],
        )

    def test_transcription_rejects_invalid_base64_extra_fields_and_traversal(self):
        base = {
            "audio_base64": "not base64!",
            "filename": "recording.webm",
            "mime_type": "audio/webm",
            "language": "de",
        }
        status, _, payload = self.request_json(
            "POST", "/v1/transcriptions", base, authenticated=True
        )
        self.assertEqual(400, status)
        self.assertEqual("invalid_audio_base64", payload["error"]["code"])

        with_extra = dict(base, audio_base64="YQ==", unexpected=True)
        status, _, payload = self.request_json(
            "POST", "/v1/transcriptions", with_extra, authenticated=True
        )
        self.assertEqual(400, status)
        self.assertEqual("invalid_transcription_payload", payload["error"]["code"])

        traversal = dict(base, audio_base64="YQ==", filename="../secret.wav")
        status, _, payload = self.request_json(
            "POST", "/v1/transcriptions", traversal, authenticated=True
        )
        self.assertEqual(400, status)
        self.assertEqual("invalid_filename", payload["error"]["code"])

    def test_transcription_rejects_unsupported_mime_type(self):
        payload = {
            "audio_base64": "YQ==",
            "filename": "recording.bin",
            "mime_type": "application/octet-stream",
            "language": "de",
        }
        status, _, response = self.request_json(
            "POST", "/v1/transcriptions", payload, authenticated=True
        )
        self.assertEqual(415, status)
        self.assertEqual("unsupported_audio_type", response["error"]["code"])

    def test_speech_returns_wav_and_correlation_header(self):
        status, headers, body = self.request(
            "POST",
            "/v1/speech",
            {"text": "  Hallo   RailTime  ", "speed": 1.25},
            authenticated=True,
            headers={"X-Request-ID": "tts-456"},
        )
        self.assertEqual(200, status)
        self.assertEqual("audio/wav", headers["Content-Type"])
        self.assertEqual("tts-456", headers["X-Request-ID"])
        self.assertEqual(self.engines.audio, body)
        self.assertEqual(("Hallo RailTime", 1.25), self.engines.synthesis_calls[0])

    def test_speech_rejects_bad_speed_and_unknown_fields(self):
        status, _, payload = self.request_json(
            "POST", "/v1/speech", {"text": "Hallo", "speed": 2.1}, authenticated=True
        )
        self.assertEqual(400, status)
        self.assertEqual("invalid_speed", payload["error"]["code"])

        status, _, payload = self.request_json(
            "POST",
            "/v1/speech",
            {"text": "Hallo", "speed": 1.0, "voice": "other"},
            authenticated=True,
        )
        self.assertEqual(400, status)
        self.assertEqual("invalid_speech_payload", payload["error"]["code"])

    def test_body_limit_is_rejected_before_body_is_read(self):
        status, _, payload = self.request_json(
            "POST",
            "/v1/speech",
            {},
            authenticated=True,
            headers={"Content-Type": "application/json", "Content-Length": "100001"},
        )
        self.assertEqual(413, status)
        self.assertEqual("body_too_large", payload["error"]["code"])

    def test_engine_queue_is_bounded(self):
        self.assertTrue(self.app._whisper_slots.acquire(blocking=False))
        try:
            payload = {
                "audio_base64": "YQ==",
                "filename": "recording.webm",
                "mime_type": "audio/webm",
                "language": "de",
            }
            status, headers, response = self.request_json(
                "POST", "/v1/transcriptions", payload, authenticated=True
            )
            self.assertEqual(503, status)
            self.assertEqual("transcription_busy", response["error"]["code"])
            self.assertEqual("1", headers["Retry-After"])
        finally:
            self.app._whisper_slots.release()

    def test_service_refuses_non_loopback_bind(self):
        with self.assertRaises(ValueError):
            service.SpeechHTTPServer(("0.0.0.0", 0), self.app)

    def test_http_thread_capacity_is_bounded_before_a_worker_is_spawned(self):
        self.assertTrue(self.server._request_slots.acquire(blocking=False))
        acquired = 1
        try:
            while self.server._request_slots.acquire(blocking=False):
                acquired += 1

            status, headers, payload = self.request_json("GET", "/healthz")
            self.assertEqual(503, status)
            self.assertEqual("server_busy", payload["error"]["code"])
            self.assertEqual("1", headers["Retry-After"])
            self.assertTrue(headers["X-Request-ID"])
        finally:
            for _ in range(acquired):
                self.server._request_slots.release()


class AuthenticationAndConfigTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()

    def tearDown(self):
        self.temporary.cleanup()

    def test_unknown_client_still_uses_constant_time_digest_comparison(self):
        config = make_config(self.temporary.name)
        app = service.SpeechApplication(config, FakeEngines())
        with mock.patch.object(service.hmac, "compare_digest", wraps=service.hmac.compare_digest) as compare:
            self.assertFalse(app.authenticate("not-configured", "wrong"))
        compare.assert_called_once()
        self.assertEqual(64, len(compare.call_args.args[0]))
        self.assertEqual(64, len(compare.call_args.args[1]))

    def test_plaintext_token_in_configuration_is_rejected(self):
        raw = config_mapping(self.temporary.name)
        raw["clients"]["railtime"]["token"] = "forbidden"
        with self.assertRaises(service.ConfigurationError):
            service.ServiceConfig.from_mapping(raw)

    def test_each_client_requires_a_distinct_token_hash(self):
        raw = config_mapping(self.temporary.name)
        raw["clients"]["followflow"]["token_sha256"] = raw["clients"]["railtime"][
            "token_sha256"
        ]
        with self.assertRaisesRegex(service.ConfigurationError, "distinct token hash"):
            service.ServiceConfig.from_mapping(raw)

    def test_lmz_speech_config_can_be_loaded_from_explicit_env_file(self):
        config_path = Path(self.temporary.name) / "config.json"
        config_path.write_text(json.dumps(config_mapping(self.temporary.name)), encoding="utf-8")
        env_path = Path(self.temporary.name) / "service.env"
        env_path.write_text("LMZ_SPEECH_CONFIG=%s\n" % config_path, encoding="utf-8")
        if os.name == "posix":
            config_path.chmod(0o600)
            env_path.chmod(0o600)
        with mock.patch.dict(os.environ, {}, clear=True):
            service.load_env_file(env_path)
            loaded = service.load_config_from_environment()
        self.assertEqual(8092, loaded.port)
        self.assertIn("railtime", loaded.clients)

    def test_explicit_env_file_overrides_inherited_config_and_must_define_the_key(self):
        config_path = Path(self.temporary.name) / "explicit-config.json"
        config_path.write_text(json.dumps(config_mapping(self.temporary.name)), encoding="utf-8")
        env_path = Path(self.temporary.name) / "explicit-service.env"
        env_path.write_text("LMZ_SPEECH_CONFIG=%s\n" % config_path, encoding="utf-8")
        empty_env_path = Path(self.temporary.name) / "empty-service.env"
        empty_env_path.write_text("# no inherited fallback is allowed\n", encoding="utf-8")
        if os.name == "posix":
            config_path.chmod(0o600)
            env_path.chmod(0o600)
            empty_env_path.chmod(0o600)

        inherited = str(Path(self.temporary.name) / "stale-config.json")
        with mock.patch.dict(os.environ, {service.CONFIG_ENV: inherited}, clear=True):
            service.load_env_file(env_path)
            self.assertEqual(str(config_path), os.environ[service.CONFIG_ENV])
            self.assertEqual(8092, service.load_config_from_environment().port)

        with mock.patch.dict(os.environ, {service.CONFIG_ENV: str(config_path)}, clear=True):
            with self.assertRaisesRegex(service.ConfigurationError, "does not define"):
                service.load_env_file(empty_env_path)


class RecordingRunner:
    def __init__(self, generated_wav_seconds=0.05, fail_with=None):
        self.calls = []
        self.generated_wav_seconds = generated_wav_seconds
        self.fail_with = fail_with

    def run(self, command, timeout_seconds, cwd, stdin=None):
        self.calls.append((list(command), timeout_seconds, Path(cwd), stdin))
        if self.fail_with is not None:
            raise self.fail_with()
        if "-c:a" in command:
            Path(command[-1]).write_bytes(wav_bytes(self.generated_wav_seconds))
            return b""
        if "-nt" in command:
            return b"[00:00:00.000 --> 00:00:01.000] Hallo Welt\n[BLANK_AUDIO]\n"
        output_option = "--output_file" if "--output_file" in command else "--output-file"
        output_path = Path(command[command.index(output_option) + 1])
        output_path.write_bytes(wav_bytes())
        return b""


class EngineContractTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.root = Path(self.temporary.name)
        for name in ("whisper.bin", "voice.onnx", "voice.onnx.json"):
            (self.root / name).write_bytes(b"ready")
        self.config = make_config(self.temporary.name)

    def tearDown(self):
        self.temporary.cleanup()

    def test_followflow_command_contract_and_temp_cleanup(self):
        runner = RecordingRunner()
        engines = service.SpeechEngines(self.config, runner)
        transcript = engines.transcribe(b"encoded audio", ".webm", "de-DE", 60)
        audio = engines.synthesize("Hallo", 1.25)

        self.assertEqual("Hallo Welt", transcript)
        self.assertTrue(audio.startswith(b"RIFF"))
        ffmpeg = runner.calls[0][0]
        whisper = runner.calls[1][0]
        piper = runner.calls[2][0]
        self.assertEqual(["-ac", "1", "-ar", "16000", "-c:a", "pcm_s16le"], ffmpeg[-9:-3])
        self.assertEqual("60.250", ffmpeg[ffmpeg.index("-t") + 1])
        self.assertEqual("de", whisper[whisper.index("-l") + 1])
        self.assertEqual("2", whisper[whisper.index("-t") + 1])
        self.assertEqual("0.8000", piper[piper.index("--length-scale") + 1])
        self.assertEqual([], list(self.config.temp_dir.iterdir()))

    def test_converted_wav_duration_is_enforced_and_temp_is_cleaned(self):
        runner = RecordingRunner(generated_wav_seconds=2)
        engines = service.SpeechEngines(self.config, runner)
        with self.assertRaises(service.ServiceError) as raised:
            engines.transcribe(b"encoded audio", ".webm", "de", 1)
        self.assertEqual(413, raised.exception.status)
        self.assertEqual("audio_duration_exceeded", raised.exception.code)
        self.assertEqual([], list(self.config.temp_dir.iterdir()))

    def test_process_timeout_is_redacted_and_temp_is_cleaned(self):
        runner = RecordingRunner(fail_with=service.ProcessTimeoutError)
        engines = service.SpeechEngines(self.config, runner)
        with self.assertRaises(service.ServiceError) as raised:
            engines.transcribe(b"encoded audio", ".wav", "de", 60)
        self.assertEqual(504, raised.exception.status)
        self.assertEqual("ffmpeg_timeout", raised.exception.code)
        self.assertNotIn(str(self.root), raised.exception.message)
        self.assertEqual([], list(self.config.temp_dir.iterdir()))

    def test_process_runner_timeout_terminates_the_direct_process(self):
        runner = service.ProcessRunner()
        started = time.monotonic()

        with self.assertRaises(service.ProcessTimeoutError):
            runner.run(
                [sys.executable, "-c", "import time; time.sleep(60)"],
                timeout_seconds=0.2,
                cwd=self.root,
            )

        self.assertLess(time.monotonic() - started, 4)

    @unittest.skipUnless(os.name == "posix", "process-group verification requires POSIX")
    def test_process_timeout_terminates_spawned_descendants(self):
        child_pid_file = self.root / "child.pid"
        script = (
            "import pathlib, subprocess, sys, time; "
            "child=subprocess.Popen([sys.executable, '-c', 'import time; time.sleep(60)']); "
            "pathlib.Path(sys.argv[1]).write_text(str(child.pid)); "
            "time.sleep(60)"
        )
        runner = service.ProcessRunner()

        with self.assertRaises(service.ProcessTimeoutError):
            runner.run(
                [sys.executable, "-c", script, str(child_pid_file)],
                timeout_seconds=0.3,
                cwd=self.root,
            )

        child_pid = int(child_pid_file.read_text())
        deadline = time.monotonic() + 2
        while time.monotonic() < deadline and self._process_is_running(child_pid):
            time.sleep(0.05)
        self.assertFalse(self._process_is_running(child_pid))

    @staticmethod
    def _process_is_running(pid):
        try:
            os.kill(pid, 0)
        except ProcessLookupError:
            return False
        stat_file = Path("/proc") / str(pid) / "stat"
        if stat_file.exists() and stat_file.read_text().split()[2] == "Z":
            return False
        return True


class ClientTokenProvisioningTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.private_directory = Path(self.temporary.name) / "private"
        self.private_directory.mkdir()
        self.output = self.private_directory / "speech-service.token"

    def tearDown(self):
        self.temporary.cleanup()

    def test_token_is_replaced_atomically_and_only_its_hash_is_returned(self):
        first_token = "a" * 64
        first_hash = token_provisioner.provision_token(
            self.output,
            enforce_private_parent=False,
            token_factory=lambda _: first_token,
        )

        self.assertEqual(first_token, self.output.read_text(encoding="utf-8"))
        self.assertEqual(service.token_sha256(first_token), first_hash)
        if os.name == "posix":
            self.assertEqual(0o600, stat.S_IMODE(self.output.stat().st_mode))

        second_token = "b" * 64
        second_hash = token_provisioner.provision_token(
            self.output,
            enforce_private_parent=False,
            token_factory=lambda _: second_token,
        )

        self.assertEqual(second_token, self.output.read_text(encoding="utf-8"))
        self.assertEqual(service.token_sha256(second_token), second_hash)
        self.assertNotEqual(first_hash, second_hash)
        self.assertEqual([self.output], list(self.private_directory.iterdir()))

    def test_private_parent_policy_rejects_symlinks_wrong_owner_and_shared_modes(self):
        safe_parent = mock.Mock()
        safe_parent.lstat.return_value = SimpleNamespace(
            st_mode=stat.S_IFDIR | 0o700,
            st_uid=123,
        )
        token_provisioner.validate_private_parent(safe_parent, 123)

        for details in (
            SimpleNamespace(st_mode=stat.S_IFLNK | 0o700, st_uid=123),
            SimpleNamespace(st_mode=stat.S_IFDIR | 0o700, st_uid=456),
            SimpleNamespace(st_mode=stat.S_IFDIR | 0o750, st_uid=123),
            SimpleNamespace(st_mode=stat.S_IFDIR | 0o707, st_uid=123),
        ):
            unsafe_parent = mock.Mock()
            unsafe_parent.lstat.return_value = details
            with self.assertRaises(token_provisioner.ProvisioningError):
                token_provisioner.validate_private_parent(unsafe_parent, 123)

    @unittest.skipUnless(os.name == "posix", "POSIX owner and mode verification requires Linux/Unix")
    def test_posix_provisioning_enforces_real_owner_and_mode(self):
        self.private_directory.chmod(0o700)
        digest = token_provisioner.provision_token(
            self.output,
            expected_uid=os.geteuid(),
            token_factory=lambda _: "c" * 64,
        )

        self.assertEqual(service.token_sha256("c" * 64), digest)
        self.assertEqual(0o600, stat.S_IMODE(self.output.stat().st_mode))


class DeploymentContractTest(unittest.TestCase):
    def test_example_config_is_fail_closed_and_matches_application_audio_limit(self):
        raw = json.loads((SERVICE_ROOT / "config.example.json").read_text(encoding="utf-8"))

        self.assertEqual("REPLACE_WITH_RAILTIME_TOKEN_SHA256", raw["clients"]["railtime"]["token_sha256"])
        self.assertEqual(
            "REPLACE_WITH_FOLLOWFLOW_TOKEN_SHA256",
            raw["clients"]["followflow"]["token_sha256"],
        )
        self.assertEqual(8 * 1024 * 1024, raw["limits"]["max_audio_bytes"])
        with self.assertRaises(service.ConfigurationError):
            service.ServiceConfig.from_mapping(raw)

    def test_readme_keeps_protected_parents_root_owned_and_tokens_app_private(self):
        readme = (SERVICE_ROOT / "README.md").read_text(encoding="utf-8")

        self.assertIn(
            "install -d -o root -g lmz-speech -m 750 /opt/lmz-speech /etc/lmz-speech /var/lib/lmz-speech /var/log/lmz-speech",
            readme,
        )
        self.assertIn(
            "install -d -o root -g lmz-speech -m 750 /opt/lmz-speech/releases /var/lib/lmz-speech/runtime",
            readme,
        )
        self.assertIn('if [ -L "$protected_parent" ]', readme)
        self.assertIn('if [ -L "$protected_child" ]', readme)
        self.assertIn("find /var/lib/lmz-speech/runtime -type l -print -quit", readme)
        self.assertIn("find /var/lib/lmz-speech/runtime -writable -print -quit", readme)
        self.assertIn("Refusing unsafe log path", readme)
        self.assertNotIn("touch /var/log/lmz-speech/service.log", readme)
        self.assertIn(
            "install -d -o lmz-speech -g lmz-speech -m 700 /var/lib/lmz-speech/tmp",
            readme,
        )
        self.assertNotIn(
            "install -d -o lmz-speech -g lmz-speech -m 700 /var/lib/lmz-speech /var/lib/lmz-speech/tmp",
            readme,
        )
        self.assertNotIn("install -d -o lmz-speech -g lmz-speech -m 750 /var/log", readme)
        self.assertIn('test "$(id -u "$RAILTIME_PHP_USER")" != "$(id -u "$FOLLOWFLOW_PHP_USER")"', readme)
        self.assertIn("$RAILTIME_WEBSPACE/.lmz-secrets", readme)
        self.assertIn("$FOLLOWFLOW_WEBSPACE/.lmz-secrets", readme)
        self.assertIn(
            'sudo -u "$RAILTIME_PHP_USER" /usr/bin/install -d -m 700 "$RAILTIME_SECRET_DIR"',
            readme,
        )
        self.assertIn(
            'sudo -u "$FOLLOWFLOW_PHP_USER" /usr/bin/install -d -m 700 "$FOLLOWFLOW_SECRET_DIR"',
            readme,
        )
        self.assertIn('test ! -r "$FOLLOWFLOW_TOKEN_FILE"', readme)
        self.assertIn('test ! -r "$RAILTIME_TOKEN_FILE"', readme)

    def test_gitignore_blocks_local_secrets_without_hiding_templates_or_deploy_code(self):
        gitignore = (SERVICE_ROOT / ".gitignore").read_text(encoding="utf-8")
        for required_pattern in (
            "/config.json",
            "/service.env",
            "*.token",
            "*.secret",
            "/.secrets/",
            "/secrets/",
        ):
            self.assertIn(required_pattern, gitignore)

        git = shutil.which("git")
        repository_root = SERVICE_ROOT.parents[1]
        if git is None or not (repository_root / ".git").exists():
            return

        def is_ignored(path):
            result = subprocess.run(
                [git, "check-ignore", "--no-index", "--quiet", "--", path],
                cwd=SERVICE_ROOT,
                check=False,
                capture_output=True,
            )
            self.assertIn(result.returncode, (0, 1), result.stderr.decode("utf-8", errors="replace"))
            return result.returncode == 0

        for ignored_path in (
            "config.json",
            "service.env",
            "railtime.token",
            "local.secret",
            ".secrets/client.token",
            "secrets/followflow.token",
        ):
            self.assertTrue(is_ignored(ignored_path), ignored_path)
        for tracked_path in (
            "config.example.json",
            "deploy/supervisor.conf.example",
            "deploy/provision_client_token.py",
        ):
            self.assertFalse(is_ignored(tracked_path), tracked_path)

    def test_supervisor_contract_uses_private_runtime_and_explicit_release_restart(self):
        readme = (SERVICE_ROOT / "README.md").read_text(encoding="utf-8")
        supervisor = (SERVICE_ROOT / "deploy" / "supervisor.conf.example").read_text(
            encoding="utf-8"
        )

        switch_position = readme.index("mv -Tf \"$next_link\" /opt/lmz-speech/current")
        restart_position = readme.index("supervisorctl restart lmz-speech-service")
        self.assertGreater(restart_position, switch_position)
        self.assertIn("user=lmz-speech", supervisor)
        self.assertIn("umask=077", supervisor)
        self.assertIn("autorestart=true", supervisor)
        self.assertIn(
            "command=/usr/bin/python3 -I -u /opt/lmz-speech/current/speech_service.py",
            supervisor,
        )
        self.assertIn('HOME="/var/lib/lmz-speech"', supervisor)
        self.assertNotIn('HOME="/var/lib/lmz-speech/tmp"', supervisor)
        self.assertIn('TMPDIR="/var/lib/lmz-speech/tmp"', supervisor)
        self.assertIn("stdout_logfile=/var/log/lmz-speech/service.log", supervisor)


if __name__ == "__main__":
    unittest.main()
