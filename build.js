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

    // Try compressing using tar first (very fast, natively available on Windows 10/11)
    let compressed = false;
    try {
        console.log('   Trying compression with native tar...');
        execSync(`tar -caf "${zipPath}" -C "${tempDir}" odella-elementor-widgets`, { stdio: 'ignore' });
        compressed = true;
    } catch (tarError) {
        console.warn('   ⚠️ Native tar failed or not found. Falling back to PowerShell Compress-Archive...');
    }

    if (!compressed) {
        try {
            // Fallback to PowerShell Compress-Archive
            execSync(`powershell -NoProfile -Command "Compress-Archive -Path '${stagingDir}' -DestinationPath '${zipPath}' -Force"`, { stdio: 'inherit' });
            compressed = true;
        } catch (psError) {
            console.error('❌ Error: Compression failed using all methods.', psError.message);
            cleanupAndExit(1);
        }
    }

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
