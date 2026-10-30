# CRA XML schema package 1-27-1

The Canada Revenue Agency's XML schemas for information returns filed by
Internet File Transfer. `tests/Feature/Payroll/CraSlipXmlTest.php` validates
every T4 and T4A file the app generates against `T619_T4.xsd` and
`T619_T4A.xsd` here, so a change that breaks the CRA layout fails CI.

- **Package:** `xmlschm1-27-1.zip`, "version 1-27-1, last updated September 23,
  2026", the CRA's final 2027 package.
- **Downloaded from:** <https://www.canada.ca/content/dam/cra-arc/serv-info/eservices/xmlschm1-27-1.zip>,
  linked from "File information returns electronically (tax slips and
  summaries) — Get ready to file":
  <https://www.canada.ca/en/revenue-agency/services/e-services/filing-information-returns-electronically-t4-t5-other-types-returns-overview/filing-information-returns-electronically-t4-t5-other-types-returns-what-you-should-know-before.html>
- **Retrieved:** 2026-10-29.
- **SHA-256 of the zip:** `304b4379d06f2989120aa088b19b61b3b10ecbad1770c4cab50aa285038d6801`

## Which year this is for

The CRA names a package after the year files are *filed*. The 2027 T619 is
accepted from January 11, 2027 and the 2026 one until December 22, 2026, so
this package is the one for the 2026 slips, due by the end of February 2027.
For T4 and T4A it differs from 1-26-3 only in adding Other Information code 96
and widening box 56 (`prov_insu_ern_amt`) to six whole-dollar digits, so a
generated file without either also validates against 1-26-3.

## What is here

The 73 files `T619_T4.xsd` and `T619_T4A.xsd` include or import, directly or
through `complex.xsd` (which pulls in every return type's schema). They are
byte-identical to the package's `SchemasEFV-Published - 2027/` folder,
CRLF line endings included (`../.gitattributes` keeps git from normalizing
them); none is edited.

## What the schema does not check

Some rules live only in the XML specifications, and the generators follow
them too:

- T619, Electronic Transmittal, 2027V1:
  <https://www.canada.ca/en/revenue-agency/services/e-services/filing-information-returns-electronically-t4-t5-other-types-returns-overview/t619-2027.html>
- T4, Statement of Remuneration Paid, 2027V2:
  <https://www.canada.ca/en/revenue-agency/services/e-services/filing-information-returns-electronically-t4-t5-other-types-returns-overview/t619-2027/t4-2027.html>
- T4A, Statement of Pension, Retirement, Annuity, and Other Income, 2027V1:
  <https://www.canada.ca/en/revenue-agency/services/e-services/filing-information-returns-electronically-t4-t5-other-types-returns-overview/t619-2027/t4a-2027.html>
- Electronic Filing Validations errors and warnings (for example error 207,
  box 45 before 2023, and 303, a field outside its tax years):
  <https://www.canada.ca/en/revenue-agency/services/e-services/filing-information-returns-electronically-t4-t5-other-types-returns-overview/electronic-filing-validation-error-warning.html>

## Updating for a new year

The CRA publishes the next package each fall. Download it from the page above,
copy the same files into a new `tests/fixtures/cra-xml/<version>/` with a README
like this one, point `craSlipXmlSchemaErrors()` in the test at it, and read the
"What's new" notes of the T619, T4 and T4A specifications for changes the
schema alone won't show.
