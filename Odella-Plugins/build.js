const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

// Configuration
const PLUGIN_FILE = 'odella-elementor-widgets.php';
const REQUIRED_FILES = [PLUGIN_FILE, 'includes', 'assets'];
const ZIP_NAME = 'OdellaPlugins.zip';

// Resolve paths
const workspaceDir = __dirname;
const parentDir = path.dirname(workspaceDir);
const zipPath = path.join(parentDir, ZIP_NAME);
const pluginFilePath = path.join(workspaceDir, PLUGIN_FILE);
const tempDir = path.join(workspaceDir, '.build_temp');
const stagingDir = path.join(tempDir, 'odella-elementor-widgets');

console.log('🚀 Starting Odella Plugins build process...\n');

// 1. Read & Parse version
if (!fs.existsSync(pluginFilePath)) {
    console.error(`❌ Error: Plugin file not found at ${pluginFilePath}`);
    process.exit(1);
}

let content = fs.readFileSync(pluginFilePath, 'utf8');

// Regex patterns to match versions
const docblockVersionRegex = /(\*\s*Version:\s*)([0-9.]+)/i;
const constantVersionRegex = /(define\(\s*['"]NMJ_VERSION['"]\s*,\s*['"])([0-9.]+)(['"]\s*\);)/;

const docblockMatch = content.match(docblockVersionRegex);
const constantMatch = content.match(constantVersionRegex);

if (!constantMatch) {
    console.error('❌ Error: Could not find NMJ_VERSION definition in odella-elementor-widgets.php');
    process.exit(1);
}

const currentVersion = constantMatch[2];
console.log(`📦 Current Version: ${currentVersion}`);

// Increment version (patch increment, e.g. 1.0.32 -> 1.0.33)
const versionParts = currentVersion.split('.');
if (versionParts.length > 0) {
    const lastIndex = versionParts.length - 1;
    const lastNum = parseInt(versionParts[lastIndex], 10);
    if (!isNaN(lastNum)) {
        versionParts[lastIndex] = (lastNum + 1).toString();
    }
}
const newVersion = versionParts.join('.');
console.log(`🔄 Bumping Version to: ${newVersion}`);

// Replace in content
let updatedContent = content;

// Replace in docblock if matched
if (docblockMatch) {
    updatedContent = updatedContent.replace(docblockVersionRegex, `$1${newVersion}`);
} else {
    console.warn('⚠️ Warning: Could not find docblock Version comment to update.');
}

// Replace in constant definition
updatedContent = updatedContent.replace(constantVersionRegex, `$1${newVersion}$3`);

// Save updated main file
fs.writeFileSync(pluginFilePath, updatedContent, 'utf8');
console.log(`✅ Saved updated version to ${PLUGIN_FILE}`);

// Keep package.json in step. It is not the source of truth — the PHP constant
// is — but leaving it behind makes `npm version`, changelogs and any tooling
// that reads it report a version the plugin never shipped.
const packageJsonPath = path.join(workspaceDir, 'package.json');
if (fs.existsSync(packageJsonPath)) {
    try {
        const pkg = JSON.parse(fs.readFileSync(packageJsonPath, 'utf8'));
        if (pkg.version !== newVersion) {
            pkg.version = newVersion;
            fs.writeFileSync(packageJsonPath, JSON.stringify(pkg, null, 2) + '\n', 'utf8');
            console.log(`✅ Synced package.json to ${newVersion}`);
        }
    } catch (pkgError) {
        console.warn('⚠️ Warning: Could not update package.json:', pkgError.message);
    }
}

// 2. Prepare staging directory
try {
    if (fs.existsSync(tempDir)) {
        fs.rmSync(tempDir, { recursive: true, force: true });
    }
    fs.mkdirSync(stagingDir, { recursive: true });
    console.log(`📁 Created staging directory: ${stagingDir}`);

    // Copy required files/folders
    for (const item of REQUIRED_FILES) {
        const srcPath = path.join(workspaceDir, item);
        const destPath = path.join(stagingDir, item);
        
        if (!fs.existsSync(srcPath)) {
            console.error(`❌ Error: Required item not found: ${item}`);
            cleanupAndExit(1);
        }
        
        console.log(`   📄 Copying ${item}...`);
        fs.cpSync(srcPath, destPath, { recursive: true });
    }
    console.log('✅ Staging complete.');

    // 3. Compress files
    console.log(`🗜️ Packaging files into ${ZIP_NAME}...`);
    
    // Ensure parent directory exists (it should, but just in case)
    if (!fs.existsSync(parentDir)) {
        fs.mkdirSync(parentDir, { recursive: true });
    }

    // Resolve tar EXPLICITLY rather than trusting PATH.
    //
    // A bare `tar` resolves differently per shell. From PowerShell it finds
    // Windows' bundled bsdtar, which writes real ZIPs. From Git Bash it finds
    // Git's GNU tar, which (a) cannot write ZIP archives at all and (b) reads
    // "C:\path" as a remote host spec and dies with
    // "Cannot connect to C: resolve failed". The old code swallowed that error
    // and silently fell through to Compress-Archive, so which shell you built
    // from decided whether the release ZIP was valid.
    const bsdTar = process.platform === 'win32'
        ? path.join(process.env.SystemRoot || 'C:\\Windows', 'System32', 'tar.exe')
        : 'tar';

    let compressed = false;

    if (process.platform !== 'win32' || fs.existsSync(bsdTar)) {
        try {
            console.log('   Compressing with bsdtar...');
            execSync(`"${bsdTar}" -caf "${zipPath}" -C "${tempDir}" odella-elementor-widgets`, { stdio: 'pipe' });
            compressed = true;
        } catch (tarError) {
            const detail = tarError.stderr ? tarError.stderr.toString().trim() : tarError.message;
            console.warn(`   ⚠️ bsdtar failed: ${detail}`);
        }
    } else {
        console.warn(`   ⚠️ bsdtar not found at ${bsdTar}`);
    }

    if (!compressed) {
        try {
            // Last resort. Windows PowerShell 5.1's Compress-Archive writes
            // BACKSLASH path separators, which violates the ZIP spec
            // (APPNOTE 4.4.17.1 requires forward slashes). Some extractors
            // cope; on Linux many produce one flat file per entry with
            // literal backslashes in the name. Verified below either way.
            console.warn('   ⚠️ Falling back to PowerShell Compress-Archive — see separator check below.');
            execSync(`powershell -NoProfile -Command "Compress-Archive -Path '${stagingDir}' -DestinationPath '${zipPath}' -Force"`, { stdio: 'inherit' });
            compressed = true;
        } catch (psError) {
            console.error('❌ Error: Compression failed using all methods.', psError.message);
            cleanupAndExit(1);
        }
    }

    // Verify the archive stores POSIX separators. A ZIP with backslashes
    // extracts as garbage on the Linux host this plugin is deployed to, and it
    // is not obvious from looking at the file — so fail the build rather than
    // hand over a release that looks fine locally.
    const zipText = fs.readFileSync(zipPath, 'latin1');
    const backslashEntries = (zipText.match(/odella-elementor-widgets\\/g) || []).length;
    if (backslashEntries > 0) {
        console.error(`❌ Error: ZIP stores ${backslashEntries} backslash path separators and would not extract correctly on Linux.`);
        console.error('   Build from PowerShell, or ensure C:\\Windows\\System32\\tar.exe is present.');
        fs.rmSync(zipPath, { force: true });
        cleanupAndExit(1);
    }

    console.log('✅ Path separators verified (POSIX).');
    console.log(`🎉 Success! ZIP file created at: ${zipPath}`);
    cleanupAndExit(0);

} catch (err) {
    console.error('❌ Build failed with an unexpected error:', err);
    cleanupAndExit(1);
}

function cleanupAndExit(code) {
    if (fs.existsSync(tempDir)) {
        console.log('🧹 Cleaning up temporary build files...');
        try {
            fs.rmSync(tempDir, { recursive: true, force: true });
        } catch (rmError) {
            console.warn('⚠️ Could not remove temporary directory:', rmError.message);
        }
    }
    console.log('\n✨ Done.');
    process.exit(code);
}
