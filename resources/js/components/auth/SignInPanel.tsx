import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export type SignInMode = 'sso' | 'local';

export interface SignInPanelProps {
  mode: SignInMode;
  /** Where the authorization redirect starts. Empty in standalone mode. */
  ssoUrl: string;
  /** Where credentials are posted. Empty in SSO mode. */
  loginUrl: string;
  csrfToken: string;
  email: string;
  error: string;
  status: string;
}

/**
 * The sign-in surface for whichever mode the deployment runs in.
 *
 * Both branches are ordinary form/link navigation rather than fetch calls: a sign-in page
 * is the last place to add a failure mode, and the server already owns the redirect,
 * the CSRF check, and the error message.
 */
export default function SignInPanel({
  mode,
  ssoUrl,
  loginUrl,
  csrfToken,
  email,
  error,
  status,
}: SignInPanelProps) {
  return (
    <Card>
      <CardHeader>
        <CardTitle>{mode === 'sso' ? 'Continue with your organization account' : 'Sign in'}</CardTitle>
        <CardDescription>
          {mode === 'sso'
            ? 'Authentication happens at your identity provider. This application never sees your password.'
            : 'Use the credentials an operator created for you. There is no self-service registration.'}
        </CardDescription>
      </CardHeader>

      <CardContent className="space-y-4">
        {status ? (
          <Alert>
            <AlertDescription>{status}</AlertDescription>
          </Alert>
        ) : null}

        {error ? (
          <Alert variant="destructive">
            <AlertTitle>Could not sign you in</AlertTitle>
            <AlertDescription>{error}</AlertDescription>
          </Alert>
        ) : null}

        {mode === 'sso' ? (
          <Button asChild className="w-full">
            <a href={ssoUrl}>Sign in with single sign-on</a>
          </Button>
        ) : (
          <form method="POST" action={loginUrl} className="space-y-4">
            <input type="hidden" name="_token" value={csrfToken} />

            <div className="space-y-2">
              <Label htmlFor="email">Email address</Label>
              <Input
                id="email"
                name="email"
                type="email"
                autoComplete="username"
                required
                defaultValue={email}
              />
            </div>

            <div className="space-y-2">
              <Label htmlFor="password">Password</Label>
              <Input
                id="password"
                name="password"
                type="password"
                autoComplete="current-password"
                required
              />
            </div>

            <Button type="submit" className="w-full">
              Sign in
            </Button>
          </form>
        )}
      </CardContent>
    </Card>
  );
}
