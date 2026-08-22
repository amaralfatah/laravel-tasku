import { useFlashToast } from '@/hooks/use-flash-toast';
import { useAppearance } from '@/hooks/use-appearance';
import { Toaster as Sonner, type ToasterProps } from 'sonner';

function Toaster({ ...props }: ToasterProps) {
    const { appearance } = useAppearance();

    useFlashToast();

    return (
        <Sonner
            theme={appearance}
            className="toaster group"
            position="bottom-right"
            // Toasts carry the same semantic colours as badges and alerts, so
            // a success here reads identically to a success anywhere else.
            style={
                {
                    '--normal-bg': 'var(--popover)',
                    '--normal-text': 'var(--popover-foreground)',
                    '--normal-border': 'var(--border)',
                    '--success-bg': 'var(--success-subtle)',
                    '--success-text': 'var(--success)',
                    '--success-border': 'var(--success)',
                    '--error-bg': 'var(--destructive-subtle)',
                    '--error-text': 'var(--destructive)',
                    '--error-border': 'var(--destructive)',
                    '--warning-bg': 'var(--warning-subtle)',
                    '--warning-text': 'var(--warning)',
                    '--warning-border': 'var(--warning)',
                    '--info-bg': 'var(--info-subtle)',
                    '--info-text': 'var(--info)',
                    '--info-border': 'var(--info)',
                    '--border-radius': 'var(--radius-md)',
                } as React.CSSProperties
            }
            {...props}
        />
    );
}

export { Toaster };
