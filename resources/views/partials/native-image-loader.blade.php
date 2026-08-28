@once
    @push('scripts')
        <script>
            (() => {
                const decodeBase64 = (encoded) => {
                    const binary = window.atob(encoded);
                    const bytes = new Uint8Array(binary.length);

                    for (let index = 0; index < binary.length; index += 1) {
                        bytes[index] = binary.charCodeAt(index);
                    }

                    return bytes;
                };

                const loadNativeImage = async (image) => {
                    if (image.dataset.nativeImageState) {
                        return;
                    }

                    image.dataset.nativeImageState = 'loading';

                    try {
                        const response = await fetch(
                            image.dataset.nativeImage,
                            {
                                cache: 'no-store',
                                credentials: 'same-origin',
                                headers: {
                                    Accept: 'application/json',
                                    'X-Requested-With': 'XMLHttpRequest',
                                },
                            },
                        );

                        if (! response.ok) {
                            throw new Error('Image request failed.');
                        }

                        const payload = await response.json();

                        if (
                            typeof payload.mime_type !== 'string' ||
                            ! payload.mime_type.startsWith('image/') ||
                            typeof payload.base64 !== 'string'
                        ) {
                            throw new Error('Image response is invalid.');
                        }

                        const objectUrl = URL.createObjectURL(
                            new Blob(
                                [decodeBase64(payload.base64)],
                                { type: payload.mime_type },
                            ),
                        );

                        image.addEventListener(
                            'load',
                            () => URL.revokeObjectURL(objectUrl),
                            { once: true },
                        );

                        image.src = objectUrl;
                        image.dataset.nativeImageState = 'loaded';
                        image.removeAttribute('data-native-image');
                    } catch (error) {
                        image.dataset.nativeImageState = 'failed';
                        image.hidden = true;
                        console.error('Unable to display native image.', error);
                    }
                };

                const images = document.querySelectorAll(
                    'img[data-native-image]',
                );

                if ('IntersectionObserver' in window) {
                    const observer = new IntersectionObserver((entries) => {
                        entries.forEach((entry) => {
                            if (entry.isIntersecting) {
                                observer.unobserve(entry.target);
                                loadNativeImage(entry.target);
                            }
                        });
                    }, { rootMargin: '300px' });

                    images.forEach((image) => observer.observe(image));
                } else {
                    images.forEach(loadNativeImage);
                }
            })();
        </script>
    @endpush
@endonce
