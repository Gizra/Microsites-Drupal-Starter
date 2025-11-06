## Microsites Demo

## Testing

### Hostname-Based Testing

The Country OG group resolver supports a `fake_domain` query parameter for testing hostname-based resolution without DNS configuration. This is enabled when the `DTT_BASE_URL` environment variable is set (which is the case in PHPUnit tests).

**Usage in tests:**
```php
// Simulate accessing content as if on tc.example.com hostname
$this->drupalGet($country->toUrl(), ['query' => ['fake_domain' => 'tc.example.com']]);
```

This allows tests to verify hostname-based group resolution, access control, and language restrictions without requiring actual DNS entries or multiple hostnames.

