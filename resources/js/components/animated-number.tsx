import { useAnimatedNumber } from '@/lib/animation';

type Props = {
    value: number;
    format: (value: number) => string;
};

/**
 * A number that counts up to its value and glides to each update. Screen
 * readers only get the final value.
 */
export default function AnimatedNumber({ value, format }: Props) {
    const shown = useAnimatedNumber(value);

    return (
        <>
            <span aria-hidden="true">{format(shown)}</span>
            <span className="sr-only">{format(value)}</span>
        </>
    );
}
