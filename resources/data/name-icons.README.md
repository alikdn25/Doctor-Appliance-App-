# Name icon suggestions

The bundled lookup contains 16,589 distinct Latin-script names from 45 Faker locales. It is a decorative
suggestion only; no inferred gender is written to a customer record. Unknown names and names occurring in
both source categories remain neutral. A conservative additional list keeps internationally ambiguous
names such as Alex, Andrea, Kim and Sasha neutral. Manual icon choices are stored per customer and take priority.

Source: FakerPHP/Faker v1.24.1, commit e0ee18eb1e6dc3cda3ce9fd97e5a0689a88a64b5,
https://github.com/FakerPHP/Faker/tree/e0ee18eb1e6dc3cda3ce9fd97e5a0689a88a64b5/src/Faker/Provider
Literal firstNameMale/firstNameFemale arrays were combined, case/diacritics normalized, non-Latin entries
and conflicting labels removed. Source names are examples, not population statistics; no numeric confidence
is claimed. Coverage is limited and modern/rare spellings may remain neutral. MIT attribution is in name-icons.LICENSE.

The dictionary is read on the server. Customer names never go to a third-party prediction service, and the
dictionary is not included in the browser bundle. New dataset versions must keep ambiguous names neutral
and preserve saved manual choices.
