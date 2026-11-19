import { describe, expect, it } from 'vitest';
import { encodePassword } from './secret';

describe('encodePassword', () => {
  it('encode en base64 les octets UTF-8 du mot de passe', () => {
    expect(encodePassword('Kwesto!2026')).toBe('S3dlc3RvITIwMjY=');
    // Caractères hors Latin-1 : même résultat que base64_encode côté PHP
    expect(encodePassword('Été€')).toBe('w4l0w6nigqw=');
  });
});
