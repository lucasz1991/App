package main

import (
	"encoding/json"
	"flag"
	"fmt"
	"io"
	"os"
	"time"

	"railtime.local/openuem-extension/enroll"
)

const help = `RailTime Windows device enrollment (new installations only)

  device-enroll prepare
    Administrator-only. Locally creates UUID, RSA3072 key and CSR in protected
    ProgramData/OpenUEM/RailTimeEnrollment. Prints PUBLIC request JSON only.
    No network request, service installation or device task is performed.

  device-enroll install --bundle <absolute public JSON> --bundle-sha256 <reviewed SHA256>
      --ca-sha256 <reviewed CA DER fingerprint> --agent <absolute reviewed EXE>
    Administrator-only. Checks certificates/key/identity/pins; creates a disabled
    LocalSystem service after complete private configuration. No service start.

  device-enroll start
    Administrator-only. Revalidates the protected installation, changes only its
    own service to automatic and starts it. Failure disables/stops it.

  device-enroll status
    Read-only presence and SCM query; no elevation, repair or network test.

No environment variables, account passwords, force/reset/uninstall, automatic UAC,
remote control or execution secret defaults. Partial installations are preserved
and require explicit administrator investigation. Obtain reviewed bundle/CA pins
through an authenticated administrator channel, not from an untrusted download.
`

func run(args []string, p enroll.Platform, out io.Writer) error {
	if len(args) == 0 || (len(args) == 1 && (args[0] == "--help" || args[0] == "-h" || args[0] == "help")) {
		_, err := fmt.Fprint(out, help)
		return err
	}
	write := func(v any) error { return json.NewEncoder(out).Encode(v) }
	switch args[0] {
	case "prepare":
		if len(args) != 1 {
			return fmt.Errorf("prepare takes no arguments")
		}
		req, err := enroll.Prepare(p)
		if err != nil {
			return err
		}
		return write(req)
	case "install":
		f := flag.NewFlagSet("install", flag.ContinueOnError)
		f.SetOutput(io.Discard)
		bundle := f.String("bundle", "", "public bundle")
		bundlePin := f.String("bundle-sha256", "", "reviewed bundle SHA256")
		ca := f.String("ca-sha256", "", "reviewed CA SHA256")
		agent := f.String("agent", "", "reviewed native service EXE")
		if f.Parse(args[1:]) != nil || f.NArg() != 0 || *bundle == "" || *bundlePin == "" || *ca == "" || *agent == "" {
			return fmt.Errorf("install requires exactly the four documented flags")
		}
		if err := enroll.Install(p, *bundle, *bundlePin, *ca, *agent, time.Now()); err != nil {
			return err
		}
		return write(map[string]any{"installed": true, "service_disabled": true, "service_started": false, "execution_ready": false})
	case "start":
		if len(args) != 1 {
			return fmt.Errorf("start takes no arguments")
		}
		if err := enroll.Start(p, time.Now()); err != nil {
			return err
		}
		return write(map[string]any{"service_running": true, "automatic": true, "execution_ready": false, "server_admission_verified": false})
	case "status":
		if len(args) != 1 {
			return fmt.Errorf("status takes no arguments")
		}
		s, err := enroll.Inspect(p)
		if err != nil {
			return err
		}
		return write(s)
	default:
		return fmt.Errorf("unknown command; use --help")
	}
}

func main() {
	if err := run(os.Args[1:], enroll.NewPlatform(), os.Stdout); err != nil {
		// Never print input bytes, private key, INI or bundle content on errors.
		fmt.Fprintln(os.Stderr, "Enrollment did not complete:", err)
		os.Exit(1)
	}
}
