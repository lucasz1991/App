"""Generate the checked-in Xcode project deterministically, without third-party tools."""
from hashlib import sha256
from pathlib import Path

BASE = Path(__file__).resolve().parents[1] / "ios"


def ident(name):
    return sha256(name.encode()).hexdigest()[:24].upper()


def quoted(value):
    return '"' + str(value).replace('\\', '\\\\').replace('"', '\\"') + '"'


def array(values):
    return "(" + ", ".join(values) + ")"


objects = []


def obj(object_name, isa, **fields):
    objects.append((ident(object_name), "{ isa = " + isa + "; " + " ".join(key + " = " + value + ";" for key, value in fields.items()) + " }"))
    return ident(object_name)


app_names = ["RailTimeLocationApp.swift", "LocationCoordinator.swift", "APIClient.swift", "CredentialStore.swift", "PermitPolicy.swift", "HandoffLinks.swift"]
app_sources = []
app_refs = []
for name in app_names:
    ref = obj("app-file-" + name, "PBXFileReference", lastKnownFileType="sourcecode.swift", path=quoted(name), sourceTree=quoted("<group>"))
    app_refs.append(ref)
    app_sources.append(obj("app-build-" + name, "PBXBuildFile", fileRef=ref))
for name, kind in [("Info.plist", "text.plist.xml"), ("RailTimeLocation.entitlements", "text.plist.entitlements")]:
    app_refs.append(obj("app-file-" + name, "PBXFileReference", lastKnownFileType=kind, path=quoted(name), sourceTree=quoted("<group>")))
privacy_ref = obj("privacy-file", "PBXFileReference", lastKnownFileType="text.xml", path=quoted("PrivacyInfo.xcprivacy"), sourceTree=quoted("<group>"))
app_refs.append(privacy_ref)
privacy_build = obj("privacy-build", "PBXBuildFile", fileRef=privacy_ref)
test_ref = obj("test-file", "PBXFileReference", lastKnownFileType="sourcecode.swift", path=quoted("PermitPolicyTests.swift"), sourceTree=quoted("<group>"))
test_build = obj("test-build", "PBXBuildFile", fileRef=test_ref)
config_ref = obj("config-file", "PBXFileReference", lastKnownFileType="text.xcconfig", path=quoted("Config.xcconfig"), sourceTree=quoted("<group>"))
app_product = obj("app-product", "PBXFileReference", explicitFileType="wrapper.application", path=quoted("RailTimeLocation.app"), sourceTree="BUILT_PRODUCTS_DIR", includeInIndex="0")
test_product = obj("test-product", "PBXFileReference", explicitFileType="wrapper.cfbundle", path=quoted("RailTimeLocationTests.xctest"), sourceTree="BUILT_PRODUCTS_DIR", includeInIndex="0")
app_group = obj("app-group", "PBXGroup", children=array(app_refs), path=quoted("RailTimeLocation"), sourceTree=quoted("<group>"))
test_group = obj("test-group", "PBXGroup", children=array([test_ref]), path=quoted("RailTimeLocationTests"), sourceTree=quoted("<group>"))
products = obj("products-group", "PBXGroup", children=array([app_product, test_product]), name="Products", sourceTree=quoted("<group>"))
main_group = obj("main-group", "PBXGroup", children=array([app_group, test_group, config_ref, products]), sourceTree=quoted("<group>"))
app_phase = obj("app-sources", "PBXSourcesBuildPhase", buildActionMask="2147483647", files=array(app_sources), runOnlyForDeploymentPostprocessing="0")
app_resources = obj("app-resources", "PBXResourcesBuildPhase", buildActionMask="2147483647", files=array([privacy_build]), runOnlyForDeploymentPostprocessing="0")
app_frameworks = obj("app-frameworks", "PBXFrameworksBuildPhase", buildActionMask="2147483647", files="()", runOnlyForDeploymentPostprocessing="0")
test_phase = obj("test-sources", "PBXSourcesBuildPhase", buildActionMask="2147483647", files=array([test_build]), runOnlyForDeploymentPostprocessing="0")
test_frameworks = obj("test-frameworks", "PBXFrameworksBuildPhase", buildActionMask="2147483647", files="()", runOnlyForDeploymentPostprocessing="0")


def configs(owner, settings):
    refs = []
    for mode in ["Debug", "Release"]:
        entries = dict(settings)
        if owner == "project":
            entries.update({"SWIFT_OPTIMIZATION_LEVEL": "-Onone" if mode == "Debug" else "-O", "ENABLE_TESTABILITY": "YES" if mode == "Debug" else "NO"})
        refs.append(obj(owner + "-" + mode, "XCBuildConfiguration", baseConfigurationReference=config_ref,
                        buildSettings="{ " + " ".join(key + " = " + quoted(value) + ";" for key, value in entries.items()) + " }", name=mode))
    return obj(owner + "-configs", "XCConfigurationList", buildConfigurations=array(refs), defaultConfigurationIsVisible="0", defaultConfigurationName="Release")


project_configs = configs("project", {"CLANG_ENABLE_MODULES": "YES", "SDKROOT": "iphoneos", "SWIFT_VERSION": "5.0", "IPHONEOS_DEPLOYMENT_TARGET": "17.0"})
app_configs = configs("app", {"PRODUCT_NAME": "$(TARGET_NAME)", "PRODUCT_BUNDLE_IDENTIFIER": "de.railtime.location", "INFOPLIST_FILE": "RailTimeLocation/Info.plist", "CODE_SIGN_ENTITLEMENTS": "RailTimeLocation/RailTimeLocation.entitlements", "CODE_SIGN_STYLE": "Automatic", "TARGETED_DEVICE_FAMILY": "1,2", "SUPPORTED_PLATFORMS": "iphoneos iphonesimulator", "GENERATE_INFOPLIST_FILE": "NO", "SWIFT_EMIT_LOC_STRINGS": "YES", "LD_RUNPATH_SEARCH_PATHS": "$(inherited) @executable_path/Frameworks"})
test_configs = configs("test", {"PRODUCT_NAME": "$(TARGET_NAME)", "PRODUCT_BUNDLE_IDENTIFIER": "de.railtime.location.tests", "GENERATE_INFOPLIST_FILE": "YES", "TARGETED_DEVICE_FAMILY": "1,2", "TEST_HOST": "$(BUILT_PRODUCTS_DIR)/RailTimeLocation.app/RailTimeLocation", "BUNDLE_LOADER": "$(TEST_HOST)", "CODE_SIGN_STYLE": "Automatic", "LD_RUNPATH_SEARCH_PATHS": "$(inherited) @executable_path/Frameworks @loader_path/Frameworks"})
app_target_id = ident("app-target")
proxy = obj("test-proxy", "PBXContainerItemProxy", containerPortal=ident("project"), proxyType="1", remoteGlobalIDString=app_target_id, remoteInfo="RailTimeLocation")
dependency = obj("test-dependency", "PBXTargetDependency", target=app_target_id, targetProxy=proxy)
app_target = obj("app-target", "PBXNativeTarget", buildConfigurationList=app_configs, buildPhases=array([app_phase, app_frameworks, app_resources]), buildRules="()", dependencies="()", name="RailTimeLocation", productName="RailTimeLocation", productReference=app_product, productType=quoted("com.apple.product-type.application"))
test_target = obj("test-target", "PBXNativeTarget", buildConfigurationList=test_configs, buildPhases=array([test_phase, test_frameworks]), buildRules="()", dependencies=array([dependency]), name="RailTimeLocationTests", productName="RailTimeLocationTests", productReference=test_product, productType=quoted("com.apple.product-type.bundle.unit-test"))
obj("project", "PBXProject", attributes="{ BuildIndependentTargetsInParallel = YES; LastUpgradeCheck = 1600; TargetAttributes = { " + app_target + " = { CreatedOnToolsVersion = 16.0; SystemCapabilities = { com.apple.BackgroundModes = { enabled = 1; }; com.apple.AssociatedDomains = { enabled = 1; }; }; }; " + test_target + " = { CreatedOnToolsVersion = 16.0; TestTargetID = " + app_target + "; }; }; }", buildConfigurationList=project_configs, compatibilityVersion=quoted("Xcode 14.0"), developmentRegion="de", hasScannedForEncodings="0", knownRegions="(de, en, Base)", mainGroup=main_group, productRefGroup=products, projectDirPath=quoted(""), projectRoot=quoted(""), targets=array([app_target, test_target]))
project_path = BASE / "RailTimeLocation.xcodeproj"
project_path.mkdir(exist_ok=True)
project_path.joinpath("project.pbxproj").write_text("// !$*UTF8*$!\n{\n archiveVersion = 1;\n classes = {};\n objectVersion = 56;\n objects = {\n" + "\n".join(" " + key + " = " + value + ";" for key, value in objects) + "\n };\n rootObject = " + ident("project") + ";\n}\n", encoding="utf-8")
scheme_dir = project_path / "xcshareddata" / "xcschemes"
scheme_dir.mkdir(parents=True, exist_ok=True)


def buildable(target, product):
    return '<BuildableReference BuildableIdentifier="primary" BlueprintIdentifier="' + ident(target) + '" BuildableName="' + product + '" BlueprintName="' + product.split('.')[0] + '" ReferencedContainer="container:RailTimeLocation.xcodeproj"/>'


scheme_dir.joinpath("RailTimeLocation.xcscheme").write_text('<?xml version="1.0" encoding="UTF-8"?>\n<Scheme LastUpgradeVersion="1600" version="1.7">\n<BuildAction parallelizeBuildables="YES" buildImplicitDependencies="YES"><BuildActionEntries><BuildActionEntry buildForTesting="YES" buildForRunning="YES" buildForProfiling="YES" buildForArchiving="YES" buildForAnalyzing="YES">' + buildable("app-target", "RailTimeLocation.app") + '</BuildActionEntry></BuildActionEntries></BuildAction>\n<TestAction buildConfiguration="Debug" selectedDebuggerIdentifier="Xcode.DebuggerFoundation.Debugger.LLDB" selectedLauncherIdentifier="Xcode.IDEFoundation.Launcher.LLDB" shouldUseLaunchSchemeArgsEnv="YES"><Testables><TestableReference skipped="NO">' + buildable("test-target", "RailTimeLocationTests.xctest") + '</TestableReference></Testables></TestAction>\n<LaunchAction buildConfiguration="Debug" selectedDebuggerIdentifier="Xcode.DebuggerFoundation.Debugger.LLDB" selectedLauncherIdentifier="Xcode.IDEFoundation.Launcher.LLDB" launchStyle="0" useCustomWorkingDirectory="NO" ignoresPersistentStateOnLaunch="NO" debugServiceExtension="internal" allowLocationSimulation="YES"><BuildableProductRunnable runnableDebuggingMode="0">' + buildable("app-target", "RailTimeLocation.app") + '</BuildableProductRunnable></LaunchAction>\n<ProfileAction buildConfiguration="Release" shouldUseLaunchSchemeArgsEnv="YES" savedToolIdentifier="" useCustomWorkingDirectory="NO" debugServiceExtension="internal"><BuildableProductRunnable runnableDebuggingMode="0">' + buildable("app-target", "RailTimeLocation.app") + '</BuildableProductRunnable></ProfileAction>\n<AnalyzeAction buildConfiguration="Debug"/>\n<ArchiveAction buildConfiguration="Release" revealArchiveInOrganizer="YES"/>\n</Scheme>\n', encoding="utf-8")
print("Generated Xcode project and shared app/test scheme.")
