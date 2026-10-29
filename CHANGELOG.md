# Changelog

## Unreleased (1.x)

### Fixed
- Prices are in ISO 4217 minor units whatever their writing: the yen has none (`¥500` is 500 JPY,
  where it was 50000), and a thousands separator is read as such (`$1,000.00` is 100000, where it
  was 100).
