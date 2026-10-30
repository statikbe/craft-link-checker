<?php

use statikbe\linkchecker\helpers\AddressPolicy;

it('only allows public addresses, IPv4 and IPv6', function() {
    foreach (['8.8.8.8', '185.162.30.138', '2606:4700::1111', '2a00:1c98:1000:1074::d5b2:a1e0'] as $ip) {
        expect(AddressPolicy::isPublic($ip))->toBeTrue($ip);
    }
    $blocked = [
        '10.0.0.1', '172.16.0.1', '192.168.1.1', '127.0.0.1', '0.0.0.0', '169.254.169.254', '255.255.255.255',
        // Carrier-grade NAT (Alibaba Cloud metadata), IETF protocol assignments, benchmarking, multicast
        '100.100.100.200', '192.0.0.8', '198.18.0.1', '224.0.0.1',
        '::1', '::', 'fd00::1', 'fe80::1', '::ffff:127.0.0.1', '::ffff:8.8.8.8', '64:ff9b::7f00:1', '2002:7f00:1::',
        '2001:db8::1', '2001:0:4136:e378::1', 'ff02::1', '[::1]', 'not-an-ip',
    ];
    foreach ($blocked as $ip) {
        expect(AddressPolicy::isPublic($ip))->toBeFalse($ip);
    }
});

it('refuses a host when one of its addresses is private, also an IPv6 one', function() {
    $resolver = fn(array $map) => fn(string $host) => $map[$host] ?? [];

    expect(AddressPolicy::target('https://example.org/page', $resolver(['example.org' => ['93.184.215.14']])))
        ->toBe(['example.org', 443, '93.184.215.14'])
        // An AAAA record to loopback next to a public A record
        ->and(AddressPolicy::target('https://a.test/', $resolver(['a.test' => ['93.184.215.14', '::1']])))->toBe('private-address')
        ->and(AddressPolicy::target('http://b.test/', $resolver(['b.test' => ['fd00::1']])))->toBe('private-address')
        // IPv6 only: connected over IPv6
        ->and(AddressPolicy::target('http://c.test/', $resolver(['c.test' => ['2606:4700::1111']])))->toBe(['c.test', 80, '2606:4700::1111'])
        // IPv4 first when there are both
        ->and(AddressPolicy::target('http://d.test/', $resolver(['d.test' => ['2606:4700::1111', '1.1.1.1']]))[2])->toBe('1.1.1.1')
        ->and(AddressPolicy::target('http://nowhere.test/', $resolver([])))->toBe('dns');
});

it('only requests http(s) on the default ports, and checks IP addresses in the URL', function() {
    $never = fn(string $host) => throw new RuntimeException("No lookup expected for $host");

    expect(AddressPolicy::target('ftp://example.org/', $never))->toBe('not-http')
        ->and(AddressPolicy::target('file:///etc/passwd', $never))->toBe('not-http')
        ->and(AddressPolicy::target('https://example.org:8443/', $never))->toBe('port')
        ->and(AddressPolicy::target('http://example.org:22/', $never))->toBe('port')
        ->and(AddressPolicy::target('http://127.0.0.1/', $never))->toBe('private-address')
        ->and(AddressPolicy::target('http://[::1]/', $never))->toBe('private-address')
        ->and(AddressPolicy::target('http://169.254.169.254/latest/meta-data/', $never))->toBe('private-address')
        ->and(AddressPolicy::target('http://8.8.8.8/', $never))->toBe(['8.8.8.8', 80, '8.8.8.8']);
});

it('pins a host to the checked address and compares the address it connected to', function() {
    expect(AddressPolicy::pin('example.org', 443, '93.184.215.14'))->toBe('example.org:443:93.184.215.14')
        ->and(AddressPolicy::pin('example.org', 443, '2606:4700::1111'))->toBe('example.org:443:[2606:4700::1111]')
        ->and(AddressPolicy::sameAddress('93.184.215.14', '93.184.215.14'))->toBeTrue()
        ->and(AddressPolicy::sameAddress('2606:4700:0:0:0:0:0:1111', '2606:4700::1111'))->toBeTrue()
        ->and(AddressPolicy::sameAddress('10.0.0.1', '93.184.215.14'))->toBeFalse()
        ->and(AddressPolicy::sameAddress(null, '93.184.215.14'))->toBeFalse()
        ->and(AddressPolicy::sameAddress('', '93.184.215.14'))->toBeFalse();
});
