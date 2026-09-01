<?php

namespace App\Support\OutlookAddin;

use DOMDocument;

/**
 * Builds the centrally deployable Outlook add-in-only XML manifest.
 *
 * All URLs are derived from one public HTTPS base URL. The manifest contains
 * no client secret and downloading it never changes the Microsoft 365 tenant.
 */
final class OutlookAddinManifest
{
    public const ADDIN_VERSION = '1.0.0.0';

    public const MANIFEST_SCHEMA = '1.1';

    /** @param array<string, mixed>|null $configuration */
    public function __construct(private readonly ?array $configuration = null) {}

    public function enabled(): bool
    {
        return (bool) $this->value('enabled', false);
    }

    public function ready(): bool
    {
        return $this->issues() === [];
    }

    /** @return list<string> */
    public function issues(): array
    {
        $issues = [];

        if (! $this->enabled()) {
            $issues[] = 'Das Outlook-Add-in ist deaktiviert.';
        }

        if (! $this->isUuid($this->addinId())) {
            $issues[] = 'Die Outlook-Add-in-ID ist keine gueltige UUID.';
        }

        if (! $this->isHttpsBaseUrl($this->baseUrl())) {
            $issues[] = 'Die oeffentliche Outlook-Add-in-Adresse muss eine HTTPS-Basisadresse ohne Query oder Fragment sein.';
        }

        if (! $this->isUuid($this->tenantId())) {
            $issues[] = 'Die Microsoft-Entra-Tenant-ID ist keine gueltige UUID.';
        }

        if (! $this->isUuid($this->clientId())) {
            $issues[] = 'Die Microsoft-Entra-Client-ID ist keine gueltige UUID.';
        }

        if ($this->scopes() === []) {
            $issues[] = 'Mindestens ein Microsoft-Entra-Scope muss konfiguriert sein.';
        }

        if (! $this->isScopeUri($this->scopeUri())) {
            $issues[] = 'Die Microsoft-Entra-Scope-URI muss eine gueltige api://- oder HTTPS-Adresse sein.';
        }

        if (! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{2,127}\z/', $this->marker())) {
            $issues[] = 'Der Signaturmarker enthaelt ungueltige Zeichen.';
        }

        return $issues;
    }

    public function assertReady(): void
    {
        if ($this->ready()) {
            return;
        }

        throw new OutlookAddinException(
            implode(' ', $this->issues()),
            503,
            'outlook_addin_manifest_not_ready',
        );
    }

    public function addinId(): string
    {
        return trim((string) $this->value('addin_id', ''));
    }

    public function baseUrl(): string
    {
        return rtrim(trim((string) $this->value('base_url', '')), '/');
    }

    public function tenantId(): string
    {
        return trim((string) $this->value('entra.tenant_id', ''));
    }

    public function clientId(): string
    {
        return trim((string) $this->value('entra.client_id', ''));
    }

    public function scopeUri(): string
    {
        return trim((string) $this->value('entra.scope_uri', ''));
    }

    /** @return list<string> */
    public function scopes(): array
    {
        $configured = $this->value('entra.scope', '');
        $values = is_array($configured)
            ? $configured
            : preg_split('/[\s,]+/', trim((string) $configured), -1, PREG_SPLIT_NO_EMPTY);

        if (! is_array($values)) {
            return [];
        }

        $scopes = [];
        foreach ($values as $value) {
            $scope = trim((string) $value);
            if ($scope !== '' && strlen($scope) <= 512) {
                $scopes[] = $scope;
            }
        }

        return array_values(array_unique($scopes));
    }

    public function marker(): string
    {
        return trim((string) $this->value('marker', 'RT-SIGNATURE-MANAGED-V1'));
    }

    /** @return array{taskpane: string, runtime: string, runtime_js: string, icon_16: string, icon_32: string, icon_80: string, support: string} */
    public function urls(): array
    {
        $baseUrl = $this->baseUrl();

        return [
            'taskpane' => $baseUrl.'/outlook-addin/taskpane',
            'runtime' => $baseUrl.'/outlook-addin/runtime',
            'runtime_js' => $baseUrl.'/outlook-addin/runtime.js',
            'icon_16' => $baseUrl.'/outlook-addin/assets/icon-16.png',
            'icon_32' => $baseUrl.'/outlook-addin/assets/icon-32.png',
            'icon_80' => $baseUrl.'/outlook-addin/assets/icon-80.png',
            'support' => $baseUrl.'/email-templates',
        ];
    }

    /** @return array<string, mixed> */
    public function metadata(): array
    {
        return [
            'schema' => self::MANIFEST_SCHEMA,
            'version' => self::ADDIN_VERSION,
            'addin_id' => $this->addinId(),
            'base_url' => $this->baseUrl(),
            'tenant_id' => $this->tenantId(),
            'client_id' => $this->clientId(),
            'scope' => $this->scopes(),
            'scope_uri' => $this->scopeUri(),
            'marker' => $this->marker(),
            'urls' => $this->urls(),
        ];
    }

    public function render(): string
    {
        $this->assertReady();

        $id = $this->xml($this->addinId());
        $urls = array_map($this->xml(...), $this->urls());
        $appDomain = $this->xml($this->appDomain());

        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<OfficeApp xmlns="http://schemas.microsoft.com/office/appforoffice/1.1"
           xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
           xmlns:bt="http://schemas.microsoft.com/office/officeappbasictypes/1.0"
           xmlns:mailappor="http://schemas.microsoft.com/office/mailappversionoverrides/1.0"
           xsi:type="MailApp">
  <Id>{$id}</Id>
  <Version>1.0.0.0</Version>
  <ProviderName>RT Rail Time GmbH</ProviderName>
  <DefaultLocale>de-DE</DefaultLocale>
  <DisplayName DefaultValue="RailTime Outlook"/>
  <Description DefaultValue="RailTime Signaturen und E-Mail-Vorlagen in Outlook verwenden."/>
  <IconUrl DefaultValue="{$urls['icon_32']}"/>
  <HighResolutionIconUrl DefaultValue="{$urls['icon_80']}"/>
  <SupportUrl DefaultValue="{$urls['support']}"/>
  <AppDomains>
    <AppDomain>{$appDomain}</AppDomain>
  </AppDomains>
  <Hosts>
    <Host Name="Mailbox"/>
  </Hosts>
  <Requirements>
    <Sets>
      <Set Name="Mailbox" MinVersion="1.5"/>
    </Sets>
  </Requirements>
  <FormSettings>
    <Form xsi:type="ItemEdit">
      <DesktopSettings>
        <SourceLocation DefaultValue="{$urls['taskpane']}"/>
      </DesktopSettings>
    </Form>
  </FormSettings>
  <Permissions>ReadWriteItem</Permissions>
  <Rule xsi:type="RuleCollection" Mode="Or">
    <Rule xsi:type="ItemIs" ItemType="Message" FormType="Edit"/>
  </Rule>
  <DisableEntityHighlighting>false</DisableEntityHighlighting>
  <VersionOverrides xmlns="http://schemas.microsoft.com/office/mailappversionoverrides"
                    xsi:type="VersionOverridesV1_0">
    <Requirements>
      <bt:Sets DefaultMinVersion="1.5">
        <bt:Set Name="Mailbox"/>
      </bt:Sets>
    </Requirements>
    <Hosts>
      <Host xsi:type="MailHost">
        <DesktopFormFactor>
          <FunctionFile resid="Commands.Url"/>
          <ExtensionPoint xsi:type="MessageComposeCommandSurface">
            <OfficeTab id="TabDefault">
              <Group id="RailTime.Compose.Group">
                <Label resid="Group.Label"/>
                <Control xsi:type="Button" id="RailTime.Compose.Open">
                  <Label resid="Taskpane.Label"/>
                  <Supertip>
                    <Title resid="Taskpane.Label"/>
                    <Description resid="Taskpane.Tooltip"/>
                  </Supertip>
                  <Icon>
                    <bt:Image size="16" resid="Icon.16"/>
                    <bt:Image size="32" resid="Icon.32"/>
                    <bt:Image size="80" resid="Icon.80"/>
                  </Icon>
                  <Action xsi:type="ShowTaskpane">
                    <SourceLocation resid="Taskpane.Url"/>
                  </Action>
                </Control>
              </Group>
            </OfficeTab>
          </ExtensionPoint>
        </DesktopFormFactor>
      </Host>
    </Hosts>
    <Resources>
      <bt:Images>
        <bt:Image id="Icon.16" DefaultValue="{$urls['icon_16']}"/>
        <bt:Image id="Icon.32" DefaultValue="{$urls['icon_32']}"/>
        <bt:Image id="Icon.80" DefaultValue="{$urls['icon_80']}"/>
      </bt:Images>
      <bt:Urls>
        <bt:Url id="Commands.Url" DefaultValue="{$urls['runtime']}"/>
        <bt:Url id="Taskpane.Url" DefaultValue="{$urls['taskpane']}"/>
      </bt:Urls>
      <bt:ShortStrings>
        <bt:String id="Group.Label" DefaultValue="RailTime"/>
        <bt:String id="Taskpane.Label" DefaultValue="RailTime oeffnen"/>
      </bt:ShortStrings>
      <bt:LongStrings>
        <bt:String id="Taskpane.Tooltip" DefaultValue="Signaturen und Vorlagen von RailTime verwenden."/>
      </bt:LongStrings>
    </Resources>
    <VersionOverrides xmlns="http://schemas.microsoft.com/office/mailappversionoverrides/1.1"
                      xsi:type="VersionOverridesV1_1">
      <Requirements>
        <bt:Sets DefaultMinVersion="1.10">
          <bt:Set Name="Mailbox"/>
        </bt:Sets>
      </Requirements>
      <Hosts>
        <Host xsi:type="MailHost">
          <Runtimes>
            <Runtime resid="WebViewRuntime.Url">
              <Override type="javascript" resid="JSRuntime.Url"/>
            </Runtime>
          </Runtimes>
          <DesktopFormFactor>
            <FunctionFile resid="Commands.Url"/>
            <ExtensionPoint xsi:type="MessageComposeCommandSurface">
              <OfficeTab id="TabDefault">
                <Group id="RailTime.Compose.Group">
                  <Label resid="Group.Label"/>
                  <Control xsi:type="Button" id="RailTime.Compose.Open">
                    <Label resid="Taskpane.Label"/>
                    <Supertip>
                      <Title resid="Taskpane.Label"/>
                      <Description resid="Taskpane.Tooltip"/>
                    </Supertip>
                    <Icon>
                      <bt:Image size="16" resid="Icon.16"/>
                      <bt:Image size="32" resid="Icon.32"/>
                      <bt:Image size="80" resid="Icon.80"/>
                    </Icon>
                    <Action xsi:type="ShowTaskpane">
                      <SourceLocation resid="Taskpane.Url"/>
                    </Action>
                  </Control>
                </Group>
              </OfficeTab>
            </ExtensionPoint>
            <ExtensionPoint xsi:type="LaunchEvent">
              <LaunchEvents>
                <LaunchEvent Type="OnNewMessageCompose" FunctionName="onNewMessageComposeHandler"/>
              </LaunchEvents>
              <SourceLocation resid="WebViewRuntime.Url"/>
            </ExtensionPoint>
          </DesktopFormFactor>
          <MobileFormFactor>
            <ExtensionPoint xsi:type="LaunchEvent">
              <LaunchEvents>
                <LaunchEvent Type="OnNewMessageCompose" FunctionName="onNewMessageComposeHandler"/>
              </LaunchEvents>
              <SourceLocation resid="WebViewRuntime.Url"/>
            </ExtensionPoint>
          </MobileFormFactor>
        </Host>
      </Hosts>
      <Resources>
        <bt:Images>
          <bt:Image id="Icon.16" DefaultValue="{$urls['icon_16']}"/>
          <bt:Image id="Icon.32" DefaultValue="{$urls['icon_32']}"/>
          <bt:Image id="Icon.80" DefaultValue="{$urls['icon_80']}"/>
        </bt:Images>
        <bt:Urls>
          <bt:Url id="Commands.Url" DefaultValue="{$urls['runtime']}"/>
          <bt:Url id="Taskpane.Url" DefaultValue="{$urls['taskpane']}"/>
          <bt:Url id="WebViewRuntime.Url" DefaultValue="{$urls['runtime']}"/>
          <bt:Url id="JSRuntime.Url" DefaultValue="{$urls['runtime_js']}"/>
        </bt:Urls>
        <bt:ShortStrings>
          <bt:String id="Group.Label" DefaultValue="RailTime"/>
          <bt:String id="Taskpane.Label" DefaultValue="RailTime oeffnen"/>
        </bt:ShortStrings>
        <bt:LongStrings>
          <bt:String id="Taskpane.Tooltip" DefaultValue="Signaturen und Vorlagen von RailTime verwenden."/>
        </bt:LongStrings>
      </Resources>
    </VersionOverrides>
  </VersionOverrides>
</OfficeApp>
XML;

        $this->assertWellFormed($xml);

        return $xml."\n";
    }

    private function appDomain(): string
    {
        $parts = parse_url($this->baseUrl());
        $scheme = (string) ($parts['scheme'] ?? '');
        $host = (string) ($parts['host'] ?? '');
        $port = isset($parts['port']) ? ':'.(int) $parts['port'] : '';

        return $scheme.'://'.$host.$port;
    }

    private function isUuid(string $value): bool
    {
        return preg_match(
            '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i',
            $value,
        ) === 1;
    }

    private function isHttpsBaseUrl(string $value): bool
    {
        $parts = parse_url($value);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && trim((string) ($parts['host'] ?? '')) !== ''
            && ! isset($parts['query'])
            && ! isset($parts['fragment'])
            && ! isset($parts['user'])
            && ! isset($parts['pass']);
    }

    private function isScopeUri(string $value): bool
    {
        $parts = parse_url($value);

        return is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['api', 'https'], true)
            && trim((string) ($parts['host'] ?? '')) !== '';
    }

    private function assertWellFormed(string $xml): void
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            if (! $document->loadXML($xml, LIBXML_NONET)) {
                throw new OutlookAddinException(
                    'Das erzeugte Outlook-Manifest ist kein gueltiges XML.',
                    500,
                    'outlook_addin_manifest_invalid_xml',
                );
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function value(string $key, mixed $default = null): mixed
    {
        return data_get($this->configuration ?? config('outlook_addin', []), $key, $default);
    }
}
