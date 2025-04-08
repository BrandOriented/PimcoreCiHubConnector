# Things to consider to develop in future (TODOs)

- Abstraction over Pimcore's preview handling.

  There are too many special cases in base Pimcore. These result in obfuscated 
  mechanics. Generally we just want:

    1. Thumbnail for Asset is supported?
    2. Thumbnail for Asset is being generated?
    3. Path to thumbnail for Asset
  
  ..no matter what type of Asset is passed (Image/Document/Video/whatever).

