/**
 * The `mailto:` link that opens an e-mail draft in the owner's own mail program (RFC 6068). The widget never sends
 * anything: the link hands the recipient, the subject and the plain-text body to the mail program, which the owner
 * then sends — or not. Only real e-mail addresses go into the address part (a name such as "Herr Müller" opens a draft
 * without a recipient); a body that would make the link longer than mail programs reliably accept is left out, and
 * the caller tells the owner to copy the text instead.
 */

/** Mail programs and browsers cut or refuse longer `mailto:` links (Outlook ~2,000, some browsers ~2,000). */
export const MAX_MAIL_LINK_LENGTH = 1900;

/** A plain address: no spaces, one `@`, a dot in the domain, none of the characters that would break the link. */
const EMAIL_ADDRESS_PATTERN = /^[^\s@<>()[\]"',;:\\]+@[^\s@<>()[\]"',;:\\]+\.[^\s@<>()[\]"',;:\\.]{2,}$/;

export interface DraftMailLink {
  href: string;
  /** False when the body was left out because the link would be too long. */
  includesBody: boolean;
}

/** The addresses in a recipient line: `a@b.de, c@d.de`, `Anna Muster <anna@b.de>`; anything else is dropped. */
export const readEmailAddresses = (recipient: string | undefined): string[] =>
  (recipient ?? "")
    .split(/[,;]/)
    .map((recipientPart) => {
      const bracketedAddress = /<([^<>]+)>/.exec(recipientPart);
      return (bracketedAddress ? bracketedAddress[1] : recipientPart).trim().replace(/^mailto:/i, "");
    })
    .filter((address) => EMAIL_ADDRESS_PATTERN.test(address));

/** RFC 6068 encoding of a header value: every reserved character percent-encoded, line breaks as CRLF. */
const encodeMailHeaderValue = (value: string): string => encodeURIComponent(value.replace(/\r?\n/g, "\r\n"));

const buildMailHref = (addressPart: string, queryParts: (string | undefined)[]): string => {
  const query = queryParts.filter((queryPart): queryPart is string => queryPart !== undefined).join("&");
  return `mailto:${addressPart}${query === "" ? "" : `?${query}`}`;
};

export const buildDraftMailLink = (
  recipient: string | undefined,
  subject: string | undefined,
  plainTextBody: string,
): DraftMailLink => {
  const addressPart = readEmailAddresses(recipient)
    .map((address) => encodeURIComponent(address).replace(/%40/g, "@"))
    .join(",");
  const subjectPart = subject ? `subject=${encodeMailHeaderValue(subject)}` : undefined;
  const bodyPart = plainTextBody !== "" ? `body=${encodeMailHeaderValue(plainTextBody)}` : undefined;
  const hrefWithBody = buildMailHref(addressPart, [subjectPart, bodyPart]);
  if (hrefWithBody.length <= MAX_MAIL_LINK_LENGTH || bodyPart === undefined) {
    return { href: hrefWithBody, includesBody: bodyPart !== undefined } satisfies DraftMailLink;
  }
  return { href: buildMailHref(addressPart, [subjectPart]), includesBody: false } satisfies DraftMailLink;
};
