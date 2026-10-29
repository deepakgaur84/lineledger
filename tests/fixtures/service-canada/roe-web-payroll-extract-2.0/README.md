# ROE Web payroll extract schema, version 2.0

`PayrollExtractXmlV2.xsd` is Service Canada's schema for the ROE Web payroll
extract file (XML version 2.0, `FileVersion="W-2.0"`), unchanged.

- Source: <https://srv136.services.gc.ca/ROE-RE/ROEWeb-REWeb/pub/resource/XmlV2Schema>
  (served as `PayrollExtractXmlV2.xsd`), linked as "ROE Web schema (XSD file)" from
  <https://www.canada.ca/en/employment-social-development/programs/ei/ei-list/ei-roe.html>.
- Retrieved: 2026-10-29.
- SHA-256: `bf0297eecee8b93fa940355fbc4897c0186f7a0c61a16d53152c19361c35edd0`.

The schema only checks a file's structure. The block-by-block rules ROE Web applies
to each ROE (Block 5 is 15 characters ending in `RP` and four digits, Block 15A is a
whole number of hours rounded up, Block 15C lists consecutive pay periods, and so on)
are in "ROE Web user requirements – Appendix D: ROE payroll extract file transfer –
XML file layout and edit – Version 2.0" (page dated 2023-06-12):
<https://www.canada.ca/en/employment-social-development/programs/ei/ei-list/ei-roe/user-requirements/appendix-d.html>.
The blocks are numbered as in "How to complete the record of employment (ROE) form":
<https://www.canada.ca/en/employment-social-development/programs/ei/ei-list/reports/roe-guide.html>.

Used by `tests/Feature/Payroll/RoeXmlTest.php`. Replace the file, and update this
README, when Service Canada publishes a new version.
