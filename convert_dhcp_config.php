<?php
/**
 * Usage: php convert_dhcp_config.php path/to/dhcpd.conf
 */

if ($argc !== 2) {
    echo "Usage: php {$argv[0]} path/to/dhcpd.conf\n";
    exit(1);
}

$filename = $argv[1];
if (!file_exists($filename)) {
    echo "Error: File not found - {$filename}\n";
    exit(1);
}

$config = file_get_contents($filename);
$lines = preg_split('/\r\n|\r|\n/', $config);

$sharedNetworks = [];
$globalOptions = [];
$optionSpaces = [];
$optionDefinitions = [];
$classes = [];
$allSubnets = [];

$lineCount = count($lines);
$i = 0;

while ($i < $lineCount) {
    $line = trim($lines[$i]);

    // Skip empty lines and comments
    if (empty($line) || strpos($line, '#') === 0) {
        $i++;
        continue;
    }

    // Handle option spaces
    if (preg_match('/^option\s+space\s+(\w+);/', $line, $matches)) {
        $optionSpaces[] = $matches[1];
        $i++;
        continue;
    }

    // Handle option definitions
    if (preg_match('/^option\s+(\S+)\.(\S+)\s+code\s+(\d+)\s*=\s*(.*);/', $line, $matches)) {
        $optionDefinitions[] = [
            'space' => $matches[1],
            'name' => $matches[2],
            'code' => $matches[3],
            'type' => $matches[4],
        ];
        $i++;
        continue;
    }

    // Handle classes
    if (preg_match('/^class\s+"([^"]+)"\s*{/', $line, $matches)) {
        $className = $matches[1];
        $classContent = '';
        $braceCount = 1;
        $i++;
        while ($i < $lineCount && $braceCount > 0) {
            $line = trim($lines[$i]);
            if (strpos($line, '{') !== false) {
                $braceCount++;
            }
            if (strpos($line, '}') !== false) {
                $braceCount--;
            }
            $classContent .= $line . "\n";
            $i++;
        }
        $classes[$className] = $classContent;
        continue;
    }

    // Handle global options and settings
    if (preg_match('/^(ddns-update-style|authoritative|default-lease-time|max-lease-time|option\s+\S+)\s+(.*);/', $line, $matches)) {
        $globalOptions[$matches[1]] = $matches[2];
        $i++;
        continue;
    }

    // Handle shared-network start
    if (preg_match('/^shared-network\s+(\S+)\s*{/', $line, $matches)) {
        $sharedNetworkName = $matches[1];
        $sharedNetworkContent = '';
        $braceCount = 1;
        $i++;
        while ($i < $lineCount && $braceCount > 0) {
            $line = $lines[$i];
            if (strpos($line, '{') !== false) {
                $braceCount++;
            }
            if (strpos($line, '}') !== false) {
                $braceCount--;
            }
            $sharedNetworkContent .= $line . "\n";
            $i++;
        }
        // Parse subnets within shared network
        $subnets = parseSubnets($sharedNetworkContent, $sharedNetworkName);
        $allSubnets = array_merge($allSubnets, $subnets);
        continue;
    }

    $i++;
}

// Function to parse subnets within shared network
function parseSubnets($content, $sharedNetworkName) {
    $lines = preg_split('/\r\n|\r|\n/', $content);
    $subnetData = [];
    $currentSubnet = null;
    $currentHost = null;
    $braceCount = 0;
    $i = 0;
    $lineCount = count($lines);

    while ($i < $lineCount) {
        $line = trim($lines[$i]);

        // Skip empty lines and comments
        if (empty($line) || strpos($line, '#') === 0) {
            $i++;
            continue;
        }

        // Handle subnet start
        if (preg_match('/^subnet\s+(\d+\.\d+\.\d+\.\d+)\s+netmask\s+(\d+\.\d+\.\d+\.\d+)\s*{/', $line, $matches)) {
            $currentSubnet = [
                'subnet' => $matches[1],
                'netmask' => $matches[2],
                'options' => [],
                'ranges' => [],
                'hosts' => [],
                'shared-network' => $sharedNetworkName,
            ];
            $braceCount = 1;
            $i++;
            continue;
        }

        if ($currentSubnet !== null) {
            // Handle options within subnet
            if (preg_match('/^option\s+(\S+)\s+(.*);/', $line, $matches)) {
                $currentSubnet['options'][$matches[1]] = trim($matches[2]);
                $i++;
                continue;
            }

            // Handle ranges
            if (preg_match('/^range\s+(\d+\.\d+\.\d+\.\d+)\s+(\d+\.\d+\.\d+\.\d+);/', $line, $matches)) {
                $currentSubnet['ranges'][] = [
                    'start' => $matches[1],
                    'end' => $matches[2],
                ];
                $i++;
                continue;
            }

            // Handle host declarations
            if (preg_match('/^host\s+(\S+)\s*{/', $line, $matches)) {
                $hostName = $matches[1];
                $hostData = [
                    'name' => $hostName,
                    'fixed-address' => '',
                    'hardware ethernet' => '',
                ];
                $hostBraceCount = 1;
                $i++;
                while ($i < $lineCount && $hostBraceCount > 0) {
                    $line = trim($lines[$i]);
                    if (strpos($line, '{') !== false) {
                        $hostBraceCount++;
                    }
                    if (strpos($line, '}') !== false) {
                        $hostBraceCount--;
                        $i++;
                        continue;
                    }
                    if (preg_match('/^fixed-address\s+(\S+);/', $line, $matches)) {
                        $hostData['fixed-address'] = $matches[1];
                    }
                    if (preg_match('/^hardware ethernet\s+(\S+);/', $line, $matches)) {
                        $hostData['hardware ethernet'] = $matches[1];
                    }
                    $i++;
                }
                $currentSubnet['hosts'][] = $hostData;
                continue;
            }

            // Handle subnet end
            if (strpos($line, '}') !== false) {
                $subnetData[] = $currentSubnet;
                $currentSubnet = null;
                $i++;
                continue;
            }
        }

        $i++;
    }

    return $subnetData;
}

// Function to convert netmask to CIDR
function netmaskToCIDR($netmask) {
    $long = ip2long($netmask);
    $base = ip2long('255.255.255.255');
    return 32 - log(($long ^ $base) + 1, 2);
}

// Now, generate PowerShell commands
$psCommands = [];

// Track if option 119 has been defined
$option119Defined = false;

foreach ($allSubnets as $subnet) {
    $scopeName = $subnet['shared-network'];
    $subnetAddress = $subnet['subnet'];
    $subnetMask = $subnet['netmask'];

    // Convert netmask to CIDR notation
    $cidr = netmaskToCIDR($subnetMask);

    // Determine the scope's start and end IP addresses
    $networkAddressLong = ip2long($subnetAddress);
    $subnetSize = pow(2, 32 - $cidr);
    $firstUsableIpLong = $networkAddressLong + 1;
    $lastUsableIpLong = $networkAddressLong + $subnetSize - 2;

    // Collect all range start and end IPs to determine the overall scope range
    $rangeStarts = [];
    $rangeEnds = [];
    foreach ($subnet['ranges'] as $range) {
        $rangeStarts[] = ip2long($range['start']);
        $rangeEnds[] = ip2long($range['end']);
    }
    if (!empty($rangeStarts) && !empty($rangeEnds)) {
        $scopeStartIpLong = min($rangeStarts);
        $scopeEndIpLong = max($rangeEnds);
    } else {
        $scopeStartIpLong = $firstUsableIpLong;
        $scopeEndIpLong = $lastUsableIpLong;
    }

    $scopeStartIp = long2ip($scopeStartIpLong);
    $scopeEndIp = long2ip($scopeEndIpLong);

    // Add DHCP Scope with the correct IP address range
    $psCommands[] = "Add-DhcpServerv4Scope -Name '$scopeName' -StartRange '$scopeStartIp' -EndRange '$scopeEndIp' -SubnetMask '$subnetMask'";

    // Set Scope Options
    foreach ($subnet['options'] as $optionName => $optionValue) {
        // Map option names to option IDs
        $optionCodeMap = [
            'domain-name-servers' => '6',
            'routers' => '3',
            'domain-name' => '15',
            'domain-search' => '119',
            // Add other mappings as needed
        ];

        if (isset($optionCodeMap[$optionName])) {
            $optionId = $optionCodeMap[$optionName];
            $optionValue = str_replace(['"', ';'], '', $optionValue);
            $optionValues = array_map('trim', explode(',', $optionValue));
            $valueString = "(@(" . implode(',', array_map(function($v) { return "'$v'"; }, $optionValues)) . "))";

            // If option ID 119, define it first if not already defined
            if ($optionId == '119' && !$option119Defined) {
                $psCommands[] = "Add-DhcpServerv4OptionDefinition -OptionId 119 -Name 'Domain Search List' -Type String";
                $option119Defined = true;
            }

            $psCommands[] = "Set-DhcpServerv4OptionValue -ScopeId '$subnetAddress' -OptionId $optionId -Value $valueString";
        }
    }

    // Note: Windows DHCP server does not support multiple ranges within a single scope. You may need to adjust your ranges accordingly.

    // Add Reservations
    foreach ($subnet['hosts'] as $host) {
        // Format ClientId (MAC address without delimiters, uppercase)
        $clientId = strtoupper(str_replace([':', '-'], '', $host['hardware ethernet']));
        $description = addslashes($host['name']);
        $psCommands[] = "Add-DhcpServerv4Reservation -ScopeId '$subnetAddress' -IPAddress '{$host['fixed-address']}' -ClientId '$clientId' -Name '$description' -Description '$description'";
    }
}

// Output PowerShell commands
foreach ($psCommands as $cmd) {
    echo $cmd . "\n";
}
?>
